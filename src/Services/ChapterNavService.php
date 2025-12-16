<?php

declare(strict_types=1);

namespace EICC\StaticForge\Features\ChapterNav\Services;

use EICC\Utils\Container;
use EICC\Utils\Log;

class ChapterNavService
{
    private Log $logger;
    /** @var array<string, mixed> */
    private array $configuredMenus = [];
    private string $prevSymbol = '←';
    private string $nextSymbol = '→';

    /**
     * Map of file paths to their sequential navigation context
     * @var array<string, array<int, array{
     *     prev: array{title: string, url: string, file: string}|null,
     *     current: array{title: string, url: string, file: string},
     *     next: array{title: string, url: string, file: string}|null,
     *     html: string
     * }>>
     */
    private array $chapterNavData = [];

    public function __construct(Log $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Process chapter navigation based on menu configuration
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed> The updated parameters
     */
    public function processChapterNavigation(Container $container, array $parameters): array
    {
        // Read configuration from environment
        $this->parseConfiguredMenus($container);

        if (empty($this->configuredMenus)) {
            return $parameters;
        }

        // Get menu data from MenuBuilder
        $menuBuilderData = $parameters['features']['MenuBuilder'] ?? [];
        $menuFiles = $menuBuilderData['files'] ?? [];

        if (empty($menuFiles)) {
            return $parameters;
        }

        // Process each configured menu
        foreach ($this->configuredMenus as $menuNumber) {
            $sequentialPages = $this->extractSequentialPages($menuFiles, (int)$menuNumber);

            for ($i = 0; $i < count($sequentialPages); $i++) {
                $current = $sequentialPages[$i];
                $prev = $i > 0 ? $sequentialPages[$i - 1] : null;
                $next = $i < count($sequentialPages) - 1 ? $sequentialPages[$i + 1] : null;

                // Store navigation data keyed by source file
                if (!isset($this->chapterNavData[$current['file']])) {
                    $this->chapterNavData[$current['file']] = [];
                }

                $this->chapterNavData[$current['file']][$menuNumber] = [
                    'prev' => $prev,
                    'current' => $current,
                    'next' => $next,
                    'html' => $this->buildChapterNavHtml($prev, $current, $next)
                ];
            }
        }

        $this->logger->log('INFO', sprintf(
            'ChapterNav: Built navigation for %d files across %d menus',
            count($this->chapterNavData),
            count($this->configuredMenus)
        ));

        // Add to parameters for template access
        if (!isset($parameters['features'])) {
            $parameters['features'] = [];
        }

        $parameters['features']['ChapterNav'] = [
            'pages' => $this->chapterNavData
        ];

        // Also update the container so the renderer can access it
        $features = $container->getVariable('features') ?? [];
        $features['ChapterNav'] = [
            'pages' => $this->chapterNavData
        ];

        if ($container->hasVariable('features')) {
            $container->updateVariable('features', $features);
        } else {
            $container->setVariable('features', $features);
        }

        return $parameters;
    }

    /**
     * Parse configured menus from environment or siteconfig.yaml
     */
    public function parseConfiguredMenus(Container $container): void
    {
        // Try siteconfig.yaml first, fall back to .env
        $siteConfig = $container->getVariable('site_config') ?? [];
        $chapterNavConfig = $siteConfig['chapter_nav'] ?? [];

        if (!empty($chapterNavConfig)) {
            $menusConfig = $chapterNavConfig['menus'] ?? '';
            $this->prevSymbol = $chapterNavConfig['prev_symbol'] ?? '←';
            $this->nextSymbol = $chapterNavConfig['next_symbol'] ?? '→';
            $this->logger->log('INFO', 'ChapterNav: Configuration loaded from siteconfig.yaml');
        } else {
            // Fallback to environment variables
            $menusConfig = $container->getVariable('CHAPTER_NAV_MENUS') ?? '';
            $this->prevSymbol = $container->getVariable('CHAPTER_NAV_PREV_SYMBOL') ?? '←';
            $this->nextSymbol = $container->getVariable('CHAPTER_NAV_NEXT_SYMBOL') ?? '→';
            $this->logger->log('INFO', 'ChapterNav: Configuration loaded from .env (fallback)');
        }

        if (is_string($menusConfig)) {
            $this->configuredMenus = array_filter(array_map('trim', explode(',', $menusConfig)));
        } elseif (is_array($menusConfig)) {
            $this->configuredMenus = $menusConfig;
        } else {
            $this->configuredMenus = [];
        }
    }

    /**
     * Extract sequential pages from menu data, ignoring dropdown items
     *
     * @param array<string, mixed> $menuData
     * @return array<int, array{title: string, url: string, file: string}>
     */
    public function extractSequentialPages(array $menuData, int $menuNumber): array
    {
        if (!isset($menuData[$menuNumber])) {
            return [];
        }

        $menuItems = $menuData[$menuNumber];
        $pages = [];

        // Handle 'direct' items (menu = X format with no specific position)
        if (isset($menuItems['direct'])) {
            foreach ($menuItems['direct'] as $item) {
                $pages[] = $item;
            }
            unset($menuItems['direct']);
        }

        // Process positioned items (menu = X.Y format)
        // Ignore third-level positions (menu = X.Y.Z are dropdown items)
        ksort($menuItems);

        foreach ($menuItems as $position => $item) {
            if (!is_numeric($position)) {
                continue;
            }

            // Case 1: Item is a page itself
            if (isset($item['title'])) {
                $pages[] = $item;
            }

            // Case 2: Item has children (whether it's a page or just a container)
            if (is_array($item)) {
                $children = [];
                foreach ($item as $key => $val) {
                    // Children are stored with integer keys
                    if (is_int($key) && is_array($val) && isset($val['title'])) {
                        $children[$key] = $val;
                    }
                }

                if (!empty($children)) {
                    ksort($children);
                    foreach ($children as $key => $child) {
                        // If this is a container (no top-level title), key 0 is the label.
                        // We usually skip the label for navigation flow.
                        if (!isset($item['title']) && $key === 0) {
                            continue;
                        }
                        $pages[] = $child;
                    }
                }
            }
        }

        return $pages;
    }

    /**
     * Build HTML for chapter navigation
     *
     * @param array{title: string, url: string}|null $prev
     * @param array{title: string, url: string} $current
     * @param array{title: string, url: string}|null $next
     */
    public function buildChapterNavHtml(?array $prev, array $current, ?array $next): string
    {
        $html = '<nav class="chapter-nav">' . "\n";

        // Previous link
        if ($prev !== null) {
            $html .= '  <a href="' . htmlspecialchars($prev['url']) . '" class="chapter-nav-prev">';
            $html .= htmlspecialchars($this->prevSymbol) . ' ' . htmlspecialchars($prev['title']);
            $html .= '</a>' . "\n";
        }

        // Current page (not a link)
        $html .= '  <span class="chapter-nav-current">' . htmlspecialchars($current['title']) . '</span>' . "\n";

        // Next link
        if ($next !== null) {
            $html .= '  <a href="' . htmlspecialchars($next['url']) . '" class="chapter-nav-next">';
            $html .= htmlspecialchars($next['title']) . ' ' . htmlspecialchars($this->nextSymbol);
            $html .= '</a>' . "\n";
        }

        $html .= '</nav>' . "\n";

        return $html;
    }

    // Getters for testing
    public function getPrevSymbol(): string { return $this->prevSymbol; }
    public function getNextSymbol(): string { return $this->nextSymbol; }
    /** @return array<string, mixed> */
    public function getConfiguredMenus(): array { return $this->configuredMenus; }
}
