<?php
/**
 * Copyright © MageEnv. All rights reserved.
 * Licensed under the Open Software License 3.0 (OSL-3.0). See LICENSE.txt.
 */
declare(strict_types=1);

namespace MageEnv\DynamicCartProgressHyva\Test\Unit;

use Magento\Framework\DataObject;
use Magento\Framework\ZendEscaper;
use MageEnv\DynamicCartProgress\ViewModel\CartProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security regression for the Phase 7A Hyva blocker.
 *
 * Both Hyva templates inline the server-rendered progress payload into a
 * double-quoted "x-data" HTML attribute. The payload carries merchant-controlled
 * strings (campaignName, rewardLabel, completionMessage). If that JSON is not
 * HTML-attribute escaped, a value containing a double quote terminates the
 * attribute early and lets the merchant inject further attributes / Alpine
 * directives - stored XSS on the Hyva storefront.
 *
 * These tests pin the escaping in place and prove the actual safety boundary:
 * the rendered "x-data" attribute stays a single well-formed attribute even
 * when every merchant field is hostile, while legitimate data round-trips
 * byte-for-byte.
 */
class HyvaXDataAttributeSafetyTest extends TestCase
{
    /**
     * Attribute names the component root is allowed to carry. A breakout would
     * surface as extra attributes here.
     */
    private const ALLOWED_ROOT_ATTRIBUTES = [
        'x-data', 'x-show', 'x-cloak', 'class', ':class', 'role', ':aria-label',
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function templateProvider(): array
    {
        return [
            'minicart' => ['view/frontend/templates/minicart/progress.phtml', 'mageenvDcpMinicart'],
            'cart' => ['view/frontend/templates/cart/progress.phtml', 'mageenvDcpCart'],
        ];
    }

    private function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The payload must be embedded through the escaper, never raw.
     */
    #[DataProvider('templateProvider')]
    public function testTemplateEscapesTheInlinedPayload(string $relativePath, string $factory): void
    {
        $source = (string)file_get_contents($this->moduleDir() . '/' . $relativePath);

        self::assertStringContainsString(
            $factory . '(',
            $source,
            'The Alpine component factory call is missing from the template.'
        );
        self::assertStringContainsString(
            '$escaper->escapeHtmlAttr($payloadJson)',
            $source,
            'The inlined x-data payload must go through $escaper->escapeHtmlAttr().'
        );
        // The pre-fix construction and any raw echo of the payload must be gone.
        self::assertStringNotContainsString('@noEscape */ $payloadJson', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<\?=\s*\$payloadJson\s*\?>/',
            $source,
            'The payload must never be echoed into the template unescaped.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/echo\s+\$payloadJson\b/',
            $source
        );
    }

    /**
     * Render the real template with every merchant-controlled field hostile and
     * prove the x-data attribute cannot be broken out of.
     */
    #[DataProvider('templateProvider')]
    public function testRenderedXDataAttributeSurvivesHostileCampaignData(
        string $relativePath,
        string $factory
    ): void {
        $payload = $this->hostilePayload();
        $expectedJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $html = $this->render($this->moduleDir() . '/' . $relativePath, $expectedJson);
        $root = $this->firstElement($html, 'div');

        self::assertNotNull($root, 'The component root element was not rendered.');
        self::assertSame('div', $root->tagName);

        // 1. No breakout: the root carries only its own attributes, nothing the
        //    hostile strings tried to inject (x-init, onload, data-*, ...).
        $rendered = [];
        foreach ($root->attributes as $attribute) {
            $rendered[] = $attribute->name;
        }
        foreach ($rendered as $name) {
            self::assertContains(
                $name,
                self::ALLOWED_ROOT_ATTRIBUTES,
                sprintf('Unexpected attribute "%s" on the component root - the x-data attribute broke out.', $name)
            );
        }

        // 2. Behaviour preserved: the escaper is fully reversible, so the exact
        //    JSON the ViewModel produced is what Alpine receives as the argument.
        $xData = $root->getAttribute('x-data');
        self::assertStringStartsWith($factory . '(', trim($xData));
        self::assertStringEndsWith(')', trim($xData));
        self::assertStringContainsString(
            $expectedJson,
            $xData,
            'The decoded x-data argument must equal the original payload JSON byte-for-byte.'
        );

        // 3. No raw attribute metacharacter survived into the serialized markup.
        $attrRegion = $this->extractAttributeRegion($html, 'x-data');
        self::assertStringNotContainsString(
            '"',
            $attrRegion,
            'A raw double quote survived inside the serialized x-data attribute.'
        );
    }

    /**
     * The escaping mechanism the template relies on neutralises the exact
     * characters used for an attribute / Alpine-directive breakout, and is
     * losslessly reversible for legitimate data.
     */
    public function testEscaperNeutralisesAttributeBreakoutCharacters(): void
    {
        $escaper = new ZendEscaper();
        $attack = 'Big " x-init="fetch(0)" data-x=\'1\' & <b>10% OFF</b>';

        $escaped = $escaper->escapeHtmlAttr($attack);

        self::assertStringNotContainsString('"', $escaped);
        self::assertStringNotContainsString("'", $escaped);
        self::assertStringNotContainsString('<', $escaped);
        self::assertSame(
            $attack,
            html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5),
            'escapeHtmlAttr() must be losslessly reversible so legitimate campaign data renders unchanged.'
        );
    }

    /**
     * A payload whose every merchant-controlled string is an attribute-breakout attempt.
     *
     * @return array<string, mixed>
     */
    private function hostilePayload(): array
    {
        $breakout = 'Big " x-init="fetch(\'//evil\')" data-x=\'1\' & <img src=x> 10% > 5%';

        return [
            'displayable' => true,
            'campaignId' => 7,
            'campaignName' => $breakout,
            'currencyCode' => 'USD',
            'formattedComparableAmount' => '$0.00',
            'overallPercent' => 0.0,
            'nextPercent' => 0.0,
            'primaryMessage' => 'You\'re "$5.00" away from ' . $breakout,
            'completionMessages' => [],
            'minicartEnabled' => true,
            'cartPageEnabled' => true,
            'milestones' => [
                [
                    'targetAmount' => 5.0,
                    'formattedTargetAmount' => '$5.00',
                    'rewardLabel' => $breakout,
                    'rewardCode' => null,
                    'completionMessage' => '"><script>alert(1)</script> & ' . $breakout,
                    'achieved' => false,
                    'remainingAmount' => 5.0,
                    'formattedRemainingAmount' => '$5.00',
                    'percent' => 0.0,
                    'positionPercent' => 100.0,
                ],
            ],
        ];
    }

    /**
     * Render a Hyva template exactly as the layout would: a DataObject block
     * carrying the shared ViewModel, and an escaper backed by the same engine
     * Magento\Framework\Escaper uses on the storefront.
     *
     * @param string $absolutePath
     * @param string $payloadJson
     * @return string
     */
    private function render(string $absolutePath, string $payloadJson): string
    {
        $viewModel = $this->createMock(CartProgress::class);
        $viewModel->method('getJsonPayload')->willReturn($payloadJson);
        $viewModel->method('getSectionName')->willReturn('mageenv-dcp-progress');

        // $block and $escaper are the variables the Hyva template reads from scope.
        $block = new DataObject(['view_model' => $viewModel]);
        $escaper = $this->storefrontEscaper();

        ob_start();
        try {
            include $absolutePath;
        } finally {
            $markup = (string)ob_get_clean();
        }

        return $markup;
    }

    /**
     * A minimal stand-in for Magento\Framework\Escaper that delegates to the
     * real Laminas engine - the same path Escaper::escapeHtmlAttr() takes when
     * translate-inline is off (i.e. every normal storefront request).
     *
     * @return object
     */
    private function storefrontEscaper(): object
    {
        return new class (new ZendEscaper()) {
            /**
             * @param ZendEscaper $engine
             */
            public function __construct(private readonly ZendEscaper $engine)
            {
            }

            /**
             * @param string|null $string
             * @param bool $escapeSingleQuote
             * @return string
             */
            public function escapeHtmlAttr($string, $escapeSingleQuote = true): string
            {
                return $this->engine->escapeHtmlAttr((string)$string);
            }

            /**
             * @param string|null $string
             * @return string
             */
            public function escapeJs($string): string
            {
                return $this->engine->escapeJs((string)$string);
            }

            /**
             * Phase 7G: the template now emits <script src="<?= $escaper->escapeUrl(...) ?>">
             * for the external CSP-safe factory. Mirror Magento\Framework\Escaper.
             *
             * @param string|null $string
             * @return string
             */
            public function escapeUrl($string): string
            {
                return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            }
        };
    }

    /**
     * First element of the given tag name from a rendered fragment.
     *
     * @param string $html
     * @param string $tag
     * @return \DOMElement|null
     */
    private function firstElement(string $html, string $tag): ?\DOMElement
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        $node = $document->getElementsByTagName($tag)->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    /**
     * The raw serialized text between `name="` and its closing quote.
     *
     * @param string $html
     * @param string $name
     * @return string
     */
    private function extractAttributeRegion(string $html, string $name): string
    {
        $start = strpos($html, $name . '="');
        self::assertNotFalse($start, sprintf('Attribute "%s" not found in the rendered markup.', $name));

        $from = $start + strlen($name) + 2;
        $end = strpos($html, '"', $from);
        self::assertNotFalse($end, sprintf('Attribute "%s" is not closed in the rendered markup.', $name));

        return substr($html, $from, $end - $from);
    }
}
