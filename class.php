<?php

namespace JR;

use Error;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\NotFoundException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;
use Kirby\Http\Url;
use Whoops\Exception\ErrorException;

class StaticSiteGenerator
{
  protected App $_kirby;
  protected array $_pathsToCopy;
  protected string|null|false $_outputFolder;

  protected Pages $_pages;
  protected array $_fileList = [];

  protected string $_originalBaseUrl;
  protected ?\Kirby\Cms\Language $_defaultLanguage;
  protected array $_languages;
  protected bool $_ignoreUntranslatedPages = false;

  protected bool $_skipCopyingMedia = false;
  protected bool $_skipCopyingPluginAssets = false;

  protected array $_customRoutes = [];

  protected string $_indexFileName = 'index.html';

  public function __construct(App $kirby, array $pathsToCopy = null, Pages $pages = null)
  {
    $this->_kirby = $kirby;

    $this->_pathsToCopy = $pathsToCopy ?: [$kirby->roots()->assets()];
    $this->_pathsToCopy = $this->_resolveRelativePaths($this->_pathsToCopy);
    $this->_outputFolder = $this->_resolveRelativePath('./static');

    $this->_pages = $pages ?: $kirby->site()->index();

    $this->_defaultLanguage = $kirby->languages()->default();
    $this->_languages = $this->_defaultLanguage ? $kirby->languages()->keys() : [$this->_defaultLanguage];
  }

	/**
	 * @throws NotFoundException
	 * @throws InvalidArgumentException
	 * @throws \Exception
	 */
	public function generate(string $outputFolder = './static', string $baseUrl = '/', array $preserve = []): array
  {
    $this->_outputFolder = $this->_resolveRelativePath($outputFolder ?: $this->_outputFolder);
    $this->_checkOutputFolder();
    F::write($this->_outputFolder . '/.kirbystatic', '');

    $this->clearFolder($this->_outputFolder, $preserve);
    $this->generatePages($baseUrl);
    foreach ($this->_pathsToCopy as $pathToCopy) {
      $this->copyFiles($pathToCopy);
    }

    return $this->_fileList;
  }

	/**
	 * @throws NotFoundException
	 * @throws InvalidArgumentException
	 */
	public function generatePages(string $baseUrl = '/'): array
  {
    $this->_setOriginalBaseUrl();

    $baseUrl = rtrim($baseUrl, '/') . '/';

    $copyMedia = !$this->_skipCopyingMedia;
    $copyMedia && StaticSiteGeneratorMedia::setActive(true);

    $homePage = $this->_pages->findBy('isHomePage', 'true');
    if ($homePage) {
      $this->_setPageLanguage($homePage, $this->_defaultLanguage?->code());
      $this->_generatePage($homePage, $this->_outputFolder . '/' . $this->_indexFileName, $baseUrl);
    }

    foreach ($this->_languages as $languageCode) {
      $this->_generatePagesByLanguage($baseUrl, $languageCode);
    }

    foreach ($this->_customRoutes as $route) {
      $this->_generateCustomRoute($baseUrl, $route);
    }

    if ($copyMedia) {
      $this->_copyMediaFiles();

      StaticSiteGeneratorMedia::setActive(false);
      StaticSiteGeneratorMedia::clearList();
    }

    !$this->_skipCopyingPluginAssets && $this->_copyPluginAssets();

    $this->_restoreOriginalBaseUrl();
    return $this->_fileList;
  }

  public function skipMedia($skipCopyingMedia = true): static
  {
    $this->_skipCopyingMedia = $skipCopyingMedia;
    return $this;
  }

  public function skipPluginAssets($skipCopyingPluginAssets = true): static
  {
    $this->_skipCopyingPluginAssets = $skipCopyingPluginAssets;
    return $this;
  }

  public function setCustomRoutes(array $customRoutes): static
  {
    $this->_customRoutes = $customRoutes;
    return $this;
  }

  public function setIgnoreUntranslatedPages(bool $ignoreUntranslatedPages): static
  {
    $this->_ignoreUntranslatedPages = $ignoreUntranslatedPages;
    return $this;
  }

  public function setIndexFileName(string $indexFileName): static
  {
    $indexFileName = preg_replace('/[^a-z0-9.]/i', '', $indexFileName);
    if (!preg_replace('/[.]/', '', $indexFileName)) {
      return $this;
    }

    $this->_indexFileName = $indexFileName;
    return $this;
  }

  protected function _setOriginalBaseUrl(): void
  {
    if (!$this->_kirby->urls()->base()) {
      $this->_modifyBaseUrl('https://jr-ssg-base-url');
    }

    $this->_originalBaseUrl = $this->_kirby->urls()->base();
  }

  protected function _restoreOriginalBaseUrl(): void
  {
    if ($this->_originalBaseUrl === 'https://jr-ssg-base-url') {
      $this->_modifyBaseUrl('');
    }
  }

  protected function _modifyBaseUrl(string $baseUrl): void
  {
    $urls = array_map(function ($url) use ($baseUrl) {
      $newUrl = $url === '/' ? $baseUrl : $baseUrl . $url;
      return str_starts_with($url, 'http') ? $url : $newUrl;
    }, $this->_kirby->urls()->toArray());
    $this->_kirby = $this->_kirby->clone(['urls' => $urls]);
  }

	/**
	 * @throws NotFoundException
	 * @throws InvalidArgumentException
	 */
	protected function _generatePagesByLanguage(string $baseUrl, string $languageCode = null): void
  {
    foreach ($this->_pages->keys() as $key) {
      $page = $this->_pages->$key;
      if ($this->_ignoreUntranslatedPages && !$page->translation($languageCode)->exists()) {
        continue;
      }

      $this->_setPageLanguage($page, $languageCode);
      $path = str_replace($this->_originalBaseUrl, '/', $page->url());
      $path = $this->_cleanPath($this->_outputFolder . $path . '/' . $this->_indexFileName);
      try {
        $this->_generatePage($page, $path, $baseUrl);
      } catch (ErrorException $error) {
        $this->_handleRenderError($error, $key, $languageCode);
      }
    }
  }

  protected function _getRouteContent(string $routePath)
  {
    if (!$routePath) {
      return null;
    }

    $routeResult = kirby()
      ->router()
      ->call($routePath, 'GET');

    if ($routeResult instanceof Page) {
      return $routeResult;
    }

    if ($routeResult instanceof \Kirby\Http\Response) {
      $routeResult = $routeResult->body();
    }

    return is_string($routeResult) ? $routeResult : null;
  }

	/**
	 * @throws InvalidArgumentException|NotFoundException
	 */
	protected function _generateCustomRoute(string $baseUrl, array $route): void
  {
    $path = A::get($route, 'path');
    $page = A::get($route, 'page');
    $routePath = A::get($route, 'route');
    $baseUrl = A::get($route, 'baseUrl', $baseUrl);
    $data = A::get($route, 'data', []);
    $languageCode = A::get($route, 'languageCode');

    if (is_string($page)) {
      $page = page($page);
    }

    $routeContent = $page ? null : $this->_getRouteContent($routePath ?: $path);
    if ($routeContent instanceof Page) {
      $page = $routeContent;
      $routeContent = null;
    }

    if (!$path || (!$page && !$routeContent)) {
      return;
    }

    if (!$page) {
      $page = new Page(['slug' => 'static-site-generator/' . uniqid()]);
    }

    $path = $this->_cleanPath($this->_outputFolder . '/' . $path . '/' . $this->_indexFileName);
    $this->_setPageLanguage($page, $languageCode, false);
    $this->_generatePage($page, $path, $baseUrl, $data, $routeContent);
  }

  protected function _resetPage(Page|Site $page): void
  {
    $page->content = null;

    foreach ($page->children() as $child) {
      $this->_resetPage($child);
    }

    foreach ($page->files() as $file) {
      $file->content = null;
    }
  }

	/**
	 * @throws InvalidArgumentException
	 */
	protected function _setPageLanguage(Page $page, string $languageCode = null, $forceReset = true): void
  {
    $this->_resetCollections();

    $kirby = $this->_kirby;
    $kirby->setCurrentTranslation($languageCode);
    $kirby->setCurrentLanguage($languageCode);

    $site = $kirby->site();
    $this->_resetPage($site);

    if ($page->exists() || $forceReset) {
      $this->_resetPage($page);
    }

    $kirby->cache('pages')->flush();
    $site->visit($page, $languageCode);
  }

  protected function _resetCollections(): void
  {
    (function () {
      $this->collections = null;
    })->bindTo($this->_kirby, 'Kirby\\Cms\\App')($this->_kirby);
  }

	/**
	 * @throws NotFoundException
	 * @throws \Exception
	 */
	protected function _generatePage(Page $page, string $path, string $baseUrl, array $data = [], string $content = null): void
  {
    $page->setSite(null);
    $content = $content ?: $page->render($data);

    $jsonOriginalBaseUrl = trim(json_encode($this->_originalBaseUrl), '"');
    $jsonBaseUrl = trim(json_encode($baseUrl), '"');
    $content = str_replace($this->_originalBaseUrl . '/', $baseUrl, $content);
    $content = str_replace($this->_originalBaseUrl, $baseUrl, $content);
    $content = str_replace($jsonOriginalBaseUrl . '\\/', $jsonBaseUrl, $content);
    $content = str_replace($jsonOriginalBaseUrl, $jsonBaseUrl, $content);

    F::write($path, $content);

    $this->_fileList = array_unique(array_merge($this->_fileList, [$path]));
  }

	/**
	 * @throws \Exception
	 */
	public function copyFiles(string $folder = null): array
  {
    $outputFolder = $this->_outputFolder;

    if (!$folder || !file_exists($folder)) {
      return $this->_fileList;
    }

    $folderName = $this->_getFolderName($folder);
    $targetPath = $outputFolder . '/' . $folderName;

    if (is_file($folder)) {
      return $this->_copyFile($folder, $targetPath);
    }

    $this->clearFolder($targetPath);
    if (!Dir::copy($folder, $targetPath)) {
      return $this->_fileList;
    }

    $list = $this->_getFileList($targetPath, true);
    $this->_fileList = array_unique(array_merge($this->_fileList, $list));
    return $this->_fileList;
  }

  protected function _copyMediaFiles(): array
  {
    $outputFolder = $this->_outputFolder;
    $mediaList = StaticSiteGeneratorMedia::getList();

    foreach ($mediaList as $item) {
      $file = $item['root'];
      $path = str_replace($this->_originalBaseUrl, '/', $item['url']);
      $path = $this->_cleanPath($path);
      $path = $outputFolder . $path;
      $this->_copyFile($file, $path);
    }

    $this->_fileList = array_unique($this->_fileList);
    return $this->_fileList;
  }

  protected function _copyPluginAssets(): void
  {
    $outputFolder = $this->_outputFolder;
    $mediaPath = Url::path($this->_kirby->url('media'));
    $pluginAssets = array_merge(...array_values(A::map(
      $this->_kirby->plugins(),
      fn($plugin) => $plugin->assets()->data
    )));

    foreach ($pluginAssets as $asset) {
      $assetPath = $asset->path();
      $pluginPath = $asset->plugin()->name();
      $targetPath = "$outputFolder/$mediaPath/plugins/$pluginPath/$assetPath";
      $this->_copyFile($asset->root(), $targetPath);
    }
  }

  protected function _copyFile($file, $targetPath): array
  {
    if (F::copy($file, $targetPath)) {
      $this->_fileList[] = $targetPath;
    }

    return $this->_fileList;
  }

  public function clearFolder(string $folder, array $preserve = []): bool
  {
    $folder = $this->_resolveRelativePath($folder);
    $items = $this->_getFileList($folder);
    return array_reduce(
      $items,
      function ($totalResult, $item) use ($preserve) {
        $folderName = $this->_getFolderName($item);
        if (in_array($folderName, $preserve)) {
          return $totalResult;
        }

        if (str_starts_with($folderName, '.')) {
          return $totalResult;
        }

        $result = is_dir($item) === false ? F::remove($item) : Dir::remove($item);
        return $totalResult && $result;
      },
      true
    );
  }

  protected function _getFolderName(string $folder): ?string
  {
    $segments = explode(DIRECTORY_SEPARATOR, $folder);
    return array_pop($segments);
  }

  protected function _getFileList(string $path, bool $recursively = false)
  {
    $items = array_map(function ($item) {
      return str_replace('/', DIRECTORY_SEPARATOR, $item);
    }, Dir::read($path, [], true));
    if (!$recursively) {
      return $items;
    }

    return array_reduce(
      $items,
      function ($list, $item) {
        if (is_dir($item)) {
          return array_merge($list, $this->_getFileList($item, true));
        }

        return array_merge($list, [$item]);
      },
      []
    ) ?:
      [];
  }

  protected function _resolveRelativePaths(array $paths): array
  {
    return array_values(
      array_filter(
        array_map(function ($path) {
          return $this->_resolveRelativePath($path);
        }, $paths)
      )
    );
  }

  protected function _resolveRelativePath(string $path = null): bool|string|null
  {
    if (!$path || !str_starts_with($path, '.')) {
      return realpath($path) ?: $path;
    }

    $path = $this->_kirby->roots()->index() . '/' . $path;
    return realpath($path) ?: $path;
  }

  protected function _cleanPath(string $path): string
  {
    $path = str_replace('//', '/', $path);
    $path = preg_replace('/([^\/]+\.[a-z]{2,5})\/' . $this->_indexFileName . '$/i', '$1', $path);
    $path = preg_replace('/(\.[^\/.]+)\/' . $this->_indexFileName . '$/i', '$1', $path);

    if (str_contains($path, '//')) {
      return $this->_cleanPath($path);
    }

    return $path;
  }

  protected function _checkOutputFolder(): void
  {
    $folder = $this->_outputFolder;
    if (!$folder) {
      throw new Error('Error: Please specify a valid output folder!');
    }

    if (Dir::isEmpty($folder)) {
      return;
    }

    if (!Dir::isWritable($folder)) {
      throw new Error('Error: The output folder is not writable');
    }

    $fileList = array_map(function ($path) use ($folder) {
      return str_replace($folder . DIRECTORY_SEPARATOR, '', $path);
    }, $this->_getFileList($folder));

    if (in_array($this->_indexFileName, $fileList) || in_array('.kirbystatic', $fileList)) {
      return;
    }

    throw new Error(
      'Hello! It seems the given output folder "' .
        $folder .
        '" already contains other files or folders. ' .
        'Please specify a path that does not exist yet, or is empty. If it absolutely has to be this path, create ' .
        'an empty .kirbystatic file and retry. WARNING: Any contents of the output folder not starting with "." ' .
        'are erased before generation! Information on preserving individual files and folders can be found in the Readme.'
    );
  }

  protected function _handleRenderError(ErrorException $error, string $key, string $languageCode = null)
  {
    $message = $error->getMessage();
    $file = str_replace($this->_kirby->roots()->index(), '', $error->getFile());
    $line = $error->getLine();
    throw new Error(
      "Error in $file line $line while rendering page \"$key\"" .
        ($languageCode ? " ($languageCode)" : '') .
        ": $message"
    );
  }
}