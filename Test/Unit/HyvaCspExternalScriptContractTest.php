<?php
/**
 * Copyright © MageEnv. All rights reserved.
 * Licensed under the Open Software License 3.0 (OSL-3.0). See LICENSE.txt.
 */
declare(strict_types=1);

namespace MageEnv\DynamicCartProgressHyva\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract checks for the Phase 7G CSP fix (final Marketplace audit blocker H-1).
 *
 * The two Hyva templates used to define their Alpine factory functions in
 * executable inline <script> blocks. Under an enforced storefront CSP (which
 * Hyva promotes) those are blocked and the components break. Phase 7G moved the
 * factories into external, deployed JS files loaded via <script src>, which is
 * covered by the default script-src 'self' - no csp_whitelist.xml, no
 * 'unsafe-inline', no inline execution.
 *
 * These tests pin that shape in place.
 */
class HyvaCspExternalScriptContractTest extends TestCase
{
    private function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, array{template: string, js: string, factory: string}>
     */
    public static function components(): array
    {
        return [
            'cart' => [
                'template' => 'view/frontend/templates/cart/progress.phtml',
                'js' => 'view/frontend/web/js/cart-progress.js',
                'factory' => 'mageenvDcpCart',
            ],
            'minicart' => [
                'template' => 'view/frontend/templates/minicart/progress.phtml',
                'js' => 'view/frontend/web/js/minicart-progress.js',
                'factory' => 'mageenvDcpMinicart',
            ],
        ];
    }

    private function read(string $relative): string
    {
        $path = $this->moduleDir() . '/' . $relative;
        self::assertFileExists($path, $relative . ' is missing.');

        return (string)file_get_contents($path);
    }

    /**
     * 1. The external JS files exist.
     */
    #[DataProvider('components')]
    public function testExternalFactoryJsFileExists(string $template, string $js, string $factory): void
    {
        self::assertFileExists($this->moduleDir() . '/' . $js);
        self::assertStringEndsWith('.js', $js);
    }

    /**
     * 2. Neither template contains an executable inline <script> - only <script src=...>.
     */
    #[DataProvider('components')]
    public function testTemplateHasNoExecutableInlineScript(string $template, string $js, string $factory): void
    {
        $source = $this->read($template);

        // An executable inline script is a <script ...> whose opening tag has no "src" attribute.
        self::assertDoesNotMatchRegularExpression(
            '/<script\b(?![^>]*\bsrc=)[^>]*>/i',
            $source,
            'The Hyva template must not contain an executable inline <script> block.'
        );
        // The factory must no longer be *declared* in the template.
        self::assertStringNotContainsString('function ' . $factory . '(', $source);
        self::assertStringNotContainsString('window.' . $factory . ' =', $source);

        // It must load the external factory via a <script src> (a plain, blocking loader).
        self::assertMatchesRegularExpression(
            '/<script\s+src="/i',
            $source,
            'The template must load the external factory via <script src=...>.'
        );
        $assetId = 'MageEnv_DynamicCartProgressHyva::' . preg_replace('#^view/frontend/web/#', '', $js);
        self::assertStringContainsString(
            "getViewFileUrl('" . $assetId . "')",
            $source,
            'The external factory URL must be resolved through getViewFileUrl().'
        );
        self::assertStringContainsString('$escaper->escapeUrl(', $source, 'The asset URL must be escaped.');
        // No async/defer/module on our loader - it must run before Alpine evaluates x-data.
        self::assertDoesNotMatchRegularExpression('/<script\s+src="[^>]*\basync\b/is', $source);
        self::assertDoesNotMatchRegularExpression('/<script\s+src="[^>]*\bdefer\b/is', $source);
        self::assertDoesNotMatchRegularExpression('/<script\s+type="module"/is', $source);
    }

    /**
     * 3. The factory function is defined in the external JS, as a global for Alpine.
     */
    #[DataProvider('components')]
    public function testFactoryDefinedInExternalJs(string $template, string $js, string $factory): void
    {
        $source = $this->read($js);

        self::assertMatchesRegularExpression(
            '/window\.' . $factory . '\s*=\s*function\s*\(\s*initialState\s*,\s*labels\s*,\s*sectionName\s*\)/',
            $source,
            'The external JS must define window.' . $factory . '(initialState, labels, sectionName).'
        );
        // Alpine component shape preserved.
        self::assertStringContainsString('return {', $source);
        self::assertStringContainsString('state: initialState || {}', $source);
        self::assertStringContainsString("addEventListener('private-content-loaded'", $source);
        self::assertStringContainsString('visible()', $source);
        self::assertStringContainsString('statusMessage()', $source);
        // No CSP workaround, no unsafe DOM.
        self::assertDoesNotMatchRegularExpression(
            '/\beval\s*\(|new Function\s*\(|innerHTML|x-html|document\.write/',
            $source
        );
        // Still no second engine.
        self::assertStringNotContainsString('ko.', $source);
        self::assertStringNotContainsString('customerData', $source);
        self::assertStringNotContainsString('define([', $source);
    }

    /**
     * 4. The template still invokes the factory by the same name, with the same
     *    escaped-payload argument shape.
     */
    #[DataProvider('components')]
    public function testTemplateStillInvokesTheFactory(string $template, string $js, string $factory): void
    {
        $source = $this->read($template);

        self::assertStringContainsString('x-data="' . $factory . '(', $source);
        self::assertStringContainsString('$escaper->escapeHtmlAttr($payloadJson)', $source);
        self::assertStringContainsString('getSectionName()', $source);
        self::assertStringContainsString('x-cloak', $source);
    }

    /**
     * 5. No csp_whitelist.xml is introduced - the external <script src> is
     *    covered by the default script-src 'self'.
     */
    public function testNoCspWhitelistIsNeeded(): void
    {
        self::assertFileDoesNotExist($this->moduleDir() . '/etc/csp_whitelist.xml');
        self::assertFileDoesNotExist($this->moduleDir() . '/etc/frontend/csp_whitelist.xml');

        foreach (self::components() as $c) {
            $source = $this->read($c['template']);
            self::assertStringNotContainsString("'unsafe-inline'", $source);
            self::assertStringNotContainsString('registerInlineScript', $source);
        }
    }

    /**
     * 6. The C2 fix is untouched: the Hyva cart block keeps its distinct name.
     */
    public function testC2DistinctCartBlockNameIsPreserved(): void
    {
        $layout = $this->read('view/frontend/layout/hyva_checkout_cart_index.xml');

        self::assertStringContainsString('name="mageenv.dcp.cart.progress.hyva"', $layout);
        self::assertDoesNotMatchRegularExpression('/name="mageenv\.dcp\.cart\.progress"/', $layout);
    }

    /**
     * 7. Phase 8: the component CSS - including the [x-cloak] self-hide guard -
     *    now lives in an external stylesheet loaded in <head> (hyva_default.xml).
     *    The templates carry no inline <style> at all.
     */
    public function testNoInlineStyleAndXCloakGuardIsInTheExternalStylesheet(): void
    {
        foreach (self::components() as $c) {
            self::assertStringNotContainsString('<style', $this->read($c['template']));
        }

        $css = $this->read('view/frontend/web/css/mageenv-dcp.css');
        self::assertMatchesRegularExpression('/\[x-cloak\]\s*\{\s*display:\s*none\s*!important/', $css);

        $layout = $this->read('view/frontend/layout/hyva_default.xml');
        self::assertStringContainsString(
            '<css src="MageEnv_DynamicCartProgressHyva::css/mageenv-dcp.css"/>',
            $layout
        );
    }
}
