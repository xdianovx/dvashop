<?php

namespace App\Services\Legal;

use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class LegalRichContentSanitizer
{
    private const MAX_LENGTH = 60000;

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(self::MAX_LENGTH)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks();

        foreach ([
            'p' => ['style'], 'br' => [], 'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'],
            'strong' => [], 'em' => [], 'u' => [], 's' => [], 'ul' => [], 'ol' => [], 'li' => [],
            'blockquote' => [], 'hr' => [], 'a' => ['href', 'target', 'rel'],
            'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [],
            'th' => ['colspan', 'rowspan', 'style'], 'td' => ['colspan', 'rowspan', 'style'],
        ] as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(?string $content): ?string
    {
        if (! is_string($content)) {
            return null;
        }

        $content = trim($content);
        if ($content === '') {
            return null;
        }

        if (mb_strlen($content, '8bit') > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'Содержимое документа не должно превышать 60000 байт.']);
        }

        // Existing documents were stored as plain text; keep that representation
        // until an editor deliberately turns it into rich HTML.
        if (strip_tags($content) === $content) {
            return $content;
        }

        $sanitized = trim($this->sanitizer->sanitize($content));

        return $sanitized === '' ? null : $this->normalizeAttributes($sanitized);
    }

    public function render(?string $content): Htmlable
    {
        $sanitized = $this->sanitize($content);
        if ($sanitized === null) {
            return new HtmlString('');
        }

        if (strip_tags($sanitized) !== $sanitized) {
            return new HtmlString($sanitized);
        }

        $paragraphs = preg_split('/\R{2,}/u', $sanitized) ?: [];
        $html = collect($paragraphs)
            ->map(fn (string $paragraph): string => '<p>'.nl2br(e(trim($paragraph)), false).'</p>')
            ->implode('');

        return new HtmlString($html);
    }

    public function plainText(?string $content): string
    {
        $sanitized = $this->sanitize($content) ?? '';
        $text = strip_tags(str_replace(['</p>', '</li>', '</h2>', '</h3>', '</h4>', '<br>', '<br />'], "\n", $sanitized));

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function normalizeAttributes(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="legal-rich-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if ($element->hasAttribute('style')) {
                $style = $this->safeAlignment($element->getAttribute('style'));
                $style === null ? $element->removeAttribute('style') : $element->setAttribute('style', $style);
            }

            if ($element->tagName === 'a') {
                $href = trim($element->getAttribute('href'));
                if (! $this->isSafeLink($href)) {
                    $element->removeAttribute('href');
                    $element->removeAttribute('target');
                    $element->removeAttribute('rel');
                } elseif ($element->getAttribute('target') === '_blank') {
                    $element->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $element->removeAttribute('target');
                    $element->removeAttribute('rel');
                }
            }

            foreach (['colspan', 'rowspan'] as $attribute) {
                if ($element->hasAttribute($attribute)) {
                    $value = filter_var($element->getAttribute($attribute), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
                    $value === false ? $element->removeAttribute($attribute) : $element->setAttribute($attribute, (string) $value);
                }
            }
        }

        $root = $document->getElementById('legal-rich-root');
        if (! $root instanceof DOMElement) {
            return '';
        }

        $output = '';
        foreach ($root->childNodes as $node) {
            $output .= $document->saveHTML($node);
        }

        return trim($output);
    }

    private function safeAlignment(string $style): ?string
    {
        if (preg_match('/(?:^|;)\s*text-align\s*:\s*(start|center|end|justify|left|right)\s*(?:;|$)/i', $style, $matches) !== 1) {
            return null;
        }

        return 'text-align: '.mb_strtolower($matches[1]).';';
    }

    private function isSafeLink(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '//') || str_contains($href, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $href) === 1) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) !== 1) {
            return true;
        }

        return in_array(mb_strtolower((string) parse_url($href, PHP_URL_SCHEME)), ['http', 'https', 'mailto', 'tel'], true);
    }
}
