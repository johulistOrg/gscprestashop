<?php

namespace PrestaShop\Module\Seocrawleraudit\Service;

use CMS;
use Context;
use Db;
use Language;
use Link;
use PrestaShop\Module\Seocrawleraudit\Repository\AuditResultRepository;
use Product;
use Category;
use PrestaShopBundle\Service\DataProvider\LegacyContext;

class SeoCrawlerService
{
    private LegacyContext $legacyContext;
    private AuditResultRepository $repository;

    public function __construct(LegacyContext $legacyContext, AuditResultRepository $repository)
    {
        $this->legacyContext = $legacyContext;
        $this->repository = $repository;
    }

    public function runAudit(int $thinContentMinWords = 150): int
    {
        $urls = $this->collectCatalogUrls();
        $scanResults = [];

        foreach ($urls as $entry) {
            $scanResults[] = $this->scanPage($entry);
        }

        $issues = $this->buildIssues($scanResults, $thinContentMinWords);

        $this->repository->truncate();

        return $this->repository->bulkInsert($issues);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectCatalogUrls(): array
    {
        $context = $this->legacyContext->getContext();
        $idLang = (int) $context->language->id;
        $idShop = (int) $context->shop->id;
        $link = $context->link instanceof Link ? $context->link : new Link();

        $urls = [];

        $products = Product::getProducts($idLang, 0, 10000, 'id_product', 'ASC', false, true, $context);
        foreach ($products as $product) {
            $idProduct = (int) $product['id_product'];
            $urls[] = [
                'url' => $link->getProductLink($idProduct, null, null, null, $idLang, $idShop),
                'page_type' => 'product',
                'entity_id' => $idProduct,
            ];
        }

        $categories = Category::getSimpleCategories($idLang, false, false);
        foreach ($categories as $category) {
            $idCategory = (int) $category['id_category'];
            if ($idCategory <= 1) {
                continue;
            }

            $urls[] = [
                'url' => $link->getCategoryLink($idCategory, null, $idLang, null, $idShop),
                'page_type' => 'category',
                'entity_id' => $idCategory,
            ];
        }

        $cmsRows = Db::getInstance()->executeS('SELECT id_cms FROM ' . _DB_PREFIX_ . 'cms WHERE active = 1');
        foreach ($cmsRows as $cmsRow) {
            $idCms = (int) $cmsRow['id_cms'];
            $urls[] = [
                'url' => $link->getCMSLink($idCms, null, false, $idLang, $idShop),
                'page_type' => 'cms',
                'entity_id' => $idCms,
            ];
        }

        return array_values(array_unique($urls, SORT_REGULAR));
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function scanPage(array $entry): array
    {
        $url = (string) $entry['url'];
        $html = $this->fetchHtml($url);

        if ($html === '') {
            return $entry + [
                'title' => '',
                'meta_description' => '',
                'h1' => [],
                'h2' => [],
                'robots' => '',
                'hreflang' => [],
                'canonical' => '',
                'rel_next' => '',
                'rel_prev' => '',
                'word_count' => 0,
                'content_hash' => sha1(''),
                'similarity_hash' => '',
                'fetch_error' => 'Unable to fetch HTML',
            ];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $title = trim($this->extractSingleXPath($xpath, '//title'));
        $metaDescription = trim($this->extractMeta($xpath, 'description'));
        $robots = strtolower(trim($this->extractMeta($xpath, 'robots')));
        $canonical = trim($this->extractAttribute($xpath, "//link[@rel='canonical']", 'href'));
        $relNext = trim($this->extractAttribute($xpath, "//link[@rel='next']", 'href'));
        $relPrev = trim($this->extractAttribute($xpath, "//link[@rel='prev']", 'href'));

        $h1 = $this->extractMultipleXPath($xpath, '//h1');
        $h2 = $this->extractMultipleXPath($xpath, '//h2');
        $hreflang = $this->extractHreflang($xpath);

        $textContent = $this->normalizeText($xpath->evaluate('string(//body)'));
        $wordCount = str_word_count($textContent);

        return $entry + [
            'title' => $title,
            'meta_description' => $metaDescription,
            'h1' => $h1,
            'h2' => $h2,
            'robots' => $robots,
            'hreflang' => $hreflang,
            'canonical' => $canonical,
            'rel_next' => $relNext,
            'rel_prev' => $relPrev,
            'word_count' => $wordCount,
            'content_hash' => sha1($textContent),
            'similarity_hash' => substr(sha1($this->buildSimilarityFingerprint($textContent)), 0, 16),
            'fetch_error' => '',
        ];
    }

    private function fetchHtml(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => "User-Agent: PrestaShop-SEO-Crawler/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $html = @file_get_contents($url, false, $context);

        return is_string($html) ? $html : '';
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildIssues(array $pages, int $thinContentMinWords): array
    {
        $issues = [];
        $titleIndex = [];
        $metaDescriptionIndex = [];
        $contentHashIndex = [];

        foreach ($pages as $page) {
            $url = (string) $page['url'];
            $pageType = (string) $page['page_type'];
            $entityId = (int) $page['entity_id'];

            if (!empty($page['fetch_error'])) {
                $issues[] = $this->issue($page, 'crawl_error', 'error', (string) $page['fetch_error']);
                continue;
            }

            $title = (string) $page['title'];
            $titleLength = mb_strlen($title);
            if ($title === '') {
                $issues[] = $this->issue($page, 'title_missing', 'error', 'Missing <title> tag.');
            } elseif ($titleLength < 30 || $titleLength > 60) {
                $issues[] = $this->issue($page, 'title_length_out_of_range', 'warning', sprintf('Title length is %d chars.', $titleLength));
            }
            if ($title !== '') {
                $titleIndex[$title][] = $url;
            }

            $metaDescription = (string) $page['meta_description'];
            $metaLength = mb_strlen($metaDescription);
            if ($metaDescription === '') {
                $issues[] = $this->issue($page, 'meta_description_missing', 'warning', 'Missing meta description.');
            } elseif ($metaLength < 70 || $metaLength > 155) {
                $issues[] = $this->issue($page, 'meta_description_length_out_of_range', 'warning', sprintf('Meta description length is %d chars.', $metaLength));
            }
            if ($metaDescription !== '') {
                $metaDescriptionIndex[$metaDescription][] = $url;
            }

            $h1 = $page['h1'];
            if (!is_array($h1) || count($h1) === 0) {
                $issues[] = $this->issue($page, 'h1_missing', 'error', 'No H1 tag found.');
            } elseif (count($h1) > 1) {
                $issues[] = $this->issue($page, 'h1_multiple', 'warning', sprintf('%d H1 tags found.', count($h1)));
            }

            if (str_contains((string) $page['robots'], 'noindex')) {
                $issues[] = $this->issue($page, 'robots_noindex', 'info', 'Meta robots contains noindex.');
            }
            if (str_contains((string) $page['robots'], 'nofollow')) {
                $issues[] = $this->issue($page, 'robots_nofollow', 'info', 'Meta robots contains nofollow.');
            }

            if ((string) $page['canonical'] === '') {
                $issues[] = $this->issue($page, 'canonical_missing', 'warning', 'Missing canonical link.');
            }

            if (empty($page['hreflang'])) {
                $issues[] = $this->issue($page, 'hreflang_missing', 'info', 'No hreflang annotations found.');
            }

            if ((string) $page['rel_next'] !== '' || (string) $page['rel_prev'] !== '') {
                $issues[] = $this->issue(
                    $page,
                    'pagination_directive_detected',
                    'info',
                    sprintf('rel=next: %s | rel=prev: %s', (string) $page['rel_next'], (string) $page['rel_prev'])
                );
            }

            if ((int) $page['word_count'] < $thinContentMinWords) {
                $issues[] = $this->issue(
                    $page,
                    'thin_content',
                    'warning',
                    sprintf('Word count %d is lower than configured threshold (%d).', (int) $page['word_count'], $thinContentMinWords)
                );
            }

            $contentHashIndex[(string) $page['content_hash']][] = $url;
        }

        foreach ($titleIndex as $value => $urls) {
            if (count($urls) > 1) {
                foreach ($urls as $url) {
                    $issues[] = [
                        'url' => $url,
                        'page_type' => 'unknown',
                        'entity_id' => 0,
                        'issue_type' => 'title_duplicate',
                        'severity' => 'warning',
                        'details' => sprintf('Title duplicated on %d pages.', count($urls)),
                        'content_hash' => '',
                        'similarity_hash' => '',
                        'word_count' => 0,
                    ];
                }
            }
        }

        foreach ($metaDescriptionIndex as $value => $urls) {
            if (count($urls) > 1) {
                foreach ($urls as $url) {
                    $issues[] = [
                        'url' => $url,
                        'page_type' => 'unknown',
                        'entity_id' => 0,
                        'issue_type' => 'meta_description_duplicate',
                        'severity' => 'warning',
                        'details' => sprintf('Meta description duplicated on %d pages.', count($urls)),
                        'content_hash' => '',
                        'similarity_hash' => '',
                        'word_count' => 0,
                    ];
                }
            }
        }

        foreach ($contentHashIndex as $hash => $urls) {
            if ($hash !== sha1('') && count($urls) > 1) {
                foreach ($urls as $url) {
                    $issues[] = [
                        'url' => $url,
                        'page_type' => 'unknown',
                        'entity_id' => 0,
                        'issue_type' => 'content_duplicate_exact',
                        'severity' => 'error',
                        'details' => sprintf('Exact duplicate content detected on %d pages.', count($urls)),
                        'content_hash' => $hash,
                        'similarity_hash' => '',
                        'word_count' => 0,
                    ];
                }
            }
        }

        $issues = array_merge($issues, $this->buildNearDuplicateIssues($pages));

        return $issues;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildNearDuplicateIssues(array $pages): array
    {
        $issues = [];
        $count = count($pages);

        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $left = $pages[$i];
                $right = $pages[$j];

                if ($left['content_hash'] === $right['content_hash']) {
                    continue;
                }

                similar_text((string) $left['similarity_hash'], (string) $right['similarity_hash'], $similarity);

                if ($similarity >= 85) {
                    $issues[] = $this->issue(
                        $left,
                        'content_duplicate_near',
                        'warning',
                        sprintf('Near duplicate detected with %s (%.2f%% fingerprint similarity).', (string) $right['url'], $similarity)
                    );
                    $issues[] = $this->issue(
                        $right,
                        'content_duplicate_near',
                        'warning',
                        sprintf('Near duplicate detected with %s (%.2f%% fingerprint similarity).', (string) $left['url'], $similarity)
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function issue(array $page, string $issueType, string $severity, string $details): array
    {
        return [
            'url' => (string) $page['url'],
            'page_type' => (string) $page['page_type'],
            'entity_id' => (int) $page['entity_id'],
            'issue_type' => $issueType,
            'severity' => $severity,
            'details' => $details,
            'content_hash' => (string) ($page['content_hash'] ?? ''),
            'similarity_hash' => (string) ($page['similarity_hash'] ?? ''),
            'word_count' => (int) ($page['word_count'] ?? 0),
        ];
    }

    private function extractSingleXPath(\DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);

        if (!$nodes || $nodes->length === 0) {
            return '';
        }

        return (string) $nodes->item(0)->textContent;
    }

    /**
     * @return array<int, string>
     */
    private function extractMultipleXPath(\DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);
        $values = [];

        if (!$nodes) {
            return $values;
        }

        foreach ($nodes as $node) {
            $value = trim((string) $node->textContent);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function extractMeta(\DOMXPath $xpath, string $name): string
    {
        return $this->extractAttribute($xpath, sprintf("//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='%s']", strtolower($name)), 'content');
    }

    private function extractAttribute(\DOMXPath $xpath, string $query, string $attribute): string
    {
        $nodes = $xpath->query($query);

        if (!$nodes || $nodes->length === 0) {
            return '';
        }

        $node = $nodes->item(0);
        if (!$node instanceof \DOMElement) {
            return '';
        }

        return trim((string) $node->getAttribute($attribute));
    }

    /**
     * @return array<int, string>
     */
    private function extractHreflang(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query("//link[@rel='alternate' and @hreflang]");
        $values = [];

        if (!$nodes) {
            return $values;
        }

        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $values[] = sprintf('%s:%s', $node->getAttribute('hreflang'), $node->getAttribute('href'));
            }
        }

        return $values;
    }

    private function normalizeText(string $content): string
    {
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = mb_strtolower($content);

        return trim((string) preg_replace('/\s+/', ' ', $content));
    }

    private function buildSimilarityFingerprint(string $content): string
    {
        $words = preg_split('/\s+/', $content) ?: [];
        $tokens = array_slice(array_values(array_filter($words, static fn (string $word): bool => mb_strlen($word) > 3)), 0, 200);

        return implode('|', $tokens);
    }
}
