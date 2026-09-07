<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use DateTimeImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use RuntimeException;
use XMLReader;

class YandexFeedValidator
{
    /** @return array{categories_count:int,offers_count:int} */
    public function validate(string $path): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;
        $categories = [];
        $counts = [];
        $offers = 0;
        $lastId = 0;
        $container = null;
        try {
            $this->require($reader->open($path, 'UTF-8', LIBXML_NONET), 'Cannot read XML.');
            while ($reader->read()) {
                $this->require($reader->nodeType !== XMLReader::DOC_TYPE, 'DTD is forbidden.');
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                $name = $reader->name;
                if ($reader->depth <= 2) {
                    $allowed = [0 => ['yml_catalog'], 1 => ['shop'], 2 => ['name', 'company', 'url', 'currencies', 'categories', 'offers']];
                    $this->require(in_array($name, $allowed[$reader->depth], true), 'Unexpected XML structure.');
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                    if ($name === 'yml_catalog') {
                        $this->validateCatalogDate((string) $reader->getAttribute('date'));
                    }
                    if ($reader->depth === 2 && $name === 'url') {
                        $this->require($this->absoluteUrl($reader->readString()), 'Invalid shop URL.');
                    }
                    if ($reader->depth === 2) {
                        $container = $name;
                        if (in_array($name, ['name', 'company'], true)) {
                            $this->require(trim($reader->readString()) !== '', 'Empty shop '.$name.'.');
                        }
                    }
                }
                if ($reader->depth === 3) {
                    $this->require(($container === 'currencies' && $name === 'currency')
                        || ($container === 'categories' && $name === 'category')
                        || ($container === 'offers' && $name === 'offer'), 'Unexpected container child.');
                }
                if ($reader->depth === 3 && $name === 'currency') {
                    $this->require($reader->getAttribute('id') === 'RUR' && $reader->getAttribute('rate') === '1', 'Invalid currency.');
                    $counts['currency'] = ($counts['currency'] ?? 0) + 1;
                }
                if ($reader->depth === 3 && $name === 'category') {
                    $id = (string) $reader->getAttribute('id');
                    $parent = $reader->getAttribute('parentId');
                    $this->require(ctype_digit($id) && (int) $id > 0 && ! isset($categories[$id]), 'Invalid/duplicate category ID.');
                    $this->require($parent === null || isset($categories[$parent]), 'Orphan category.');
                    $this->require(trim($reader->readString()) !== '', 'Empty category.');
                    $categories[$id] = true;
                }
                if ($reader->depth === 3 && $name === 'offer') {
                    $id = (string) $reader->getAttribute('id');
                    $this->require((bool) preg_match('/^variant-([1-9][0-9]*)$/', $id, $match), 'Invalid offer ID.');
                    // Strict numeric ordering proves uniqueness with O(1) offer-ID memory.
                    $this->require((int) $match[1] > $lastId, 'Duplicate or unordered offer ID.');
                    $lastId = (int) $match[1];
                    $this->require($reader->getAttribute('available') === 'true', 'Offer is not purchasable.');
                    $document = new DOMDocument;
                    $offerXml = $reader->readOuterXml();
                    $this->require($offerXml !== '' && $document->loadXML($offerXml, LIBXML_NONET), 'Malformed offer.');
                    $offer = $document->documentElement;
                    $this->require(isset($categories[$this->one($offer, 'categoryId')]), 'Unknown offer category.');
                    $price = $this->one($offer, 'price');
                    $this->require((bool) preg_match('/^\d+\.\d{2}$/', $price) && (float) $price > 0, 'Invalid price.');
                    $this->require($this->one($offer, 'currencyId') === 'RUR', 'Invalid offer currency.');
                    $name = $this->one($offer, 'name');
                    $this->require(trim($name) !== '' && mb_strlen($name) <= 150, 'Invalid offer name.');
                    $descriptions = $offer->getElementsByTagName('description');
                    $this->require($descriptions->length <= 1, 'Duplicate offer description.');
                    if ($descriptions->length === 1) {
                        $this->require(mb_strlen($descriptions->item(0)->textContent) <= 3000, 'Invalid offer description.');
                    }
                    $offerUrl = $this->one($offer, 'url');
                    $this->require($this->absoluteUrl($offerUrl) && mb_strlen($offerUrl) <= 512, 'Invalid offer URL.');
                    foreach ($offer->getElementsByTagName('picture') as $picture) {
                        $this->require($this->absoluteUrl($picture->textContent), 'Invalid picture URL.');
                    }
                    foreach ($offer->getElementsByTagName('oldprice') as $old) {
                        $this->require((bool) preg_match('/^\d+\.\d{2}$/', $old->textContent) && (float) $old->textContent > (float) $price, 'Invalid oldprice.');
                    }
                    $offers++;
                }
            }
            $this->require(libxml_get_errors() === [], 'Malformed UTF-8 XML.');
            foreach (['yml_catalog', 'shop', 'name', 'company', 'url', 'currencies', 'currency', 'categories', 'offers'] as $name) {
                $this->require(($counts[$name] ?? 0) === 1, 'Missing/duplicate '.$name.'.');
            }

            return ['categories_count' => count($categories), 'offers_count' => $offers];
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function one(DOMElement $element, string $name): string
    {
        $nodes = $element->getElementsByTagName($name);
        $this->require($nodes->length === 1, 'Missing/duplicate '.$name.'.');

        return $nodes->item(0)->textContent;
    }

    private function absoluteUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && ! parse_url($url, PHP_URL_USER);
    }

    private function validateCatalogDate(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $value);
        $errors = DateTimeImmutable::getLastErrors();
        $valid = $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format(DateTimeInterface::RFC3339) === $value;

        $this->require($valid, 'Missing or invalid RFC3339 generation date.');
        $this->require($date->getTimestamp() <= now()->getTimestamp(), 'Future generation date.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException('Yandex feed validation: '.$message);
        }
    }
}
