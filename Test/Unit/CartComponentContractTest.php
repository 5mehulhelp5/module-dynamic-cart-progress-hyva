<?php
/**
 * Copyright © MageEnv. All rights reserved.
 * Licensed under the Open Software License 3.0 (OSL-3.0). See LICENSE.txt.
 */
declare(strict_types=1);

namespace MageEnv\DynamicCartProgressHyva\Test\Unit;

use MageEnv\DynamicCartProgress\ViewModel\CartProgress;
use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for the Hyva Cart Page Alpine component: one
 * sentence, one bar in the campaign's progress style, the reward tiers.
 *
 * Theme parity with Luma; consumes only the shared payload + shared ViewModel;
 * renders no percentage text. The bar reads overallPercent / nextPercent /
 * positionPercent but only hands each one, untouched, to a CSS custom property.
 */
class CartComponentContractTest extends TestCase
{
    private const PAYLOAD_TOP_KEYS = [
        'displayable', 'campaignId', 'campaignName', 'progressStyle', 'currencyCode', 'formattedComparableAmount',
        'overallPercent', 'nextPercent', 'primaryMessage', 'completionMessages',
        'minicartEnabled', 'cartPageEnabled', 'milestones',
    ];

    private const PAYLOAD_MILESTONE_KEYS = [
        'targetAmount', 'formattedTargetAmount', 'rewardLabel', 'rewardCode', 'completionMessage',
        'achieved', 'remainingAmount', 'formattedRemainingAmount', 'percent', 'positionPercent',
    ];

    private function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    private function template(): string
    {
        return (string)file_get_contents(
            $this->moduleDir() . '/view/frontend/templates/cart/progress.phtml'
        );
    }

    private function factoryJs(): string
    {
        return (string)file_get_contents(
            $this->moduleDir() . '/view/frontend/web/js/cart-progress.js'
        );
    }

    private function css(): string
    {
        return (string)file_get_contents(
            $this->moduleDir() . '/view/frontend/web/css/mageenv-dcp.css'
        );
    }

    private function componentCode(): string
    {
        return $this->template() . "\n" . $this->factoryJs();
    }

    public function testTemplateUsesTheSharedViewModelAndSection(): void
    {
        $template = $this->template();

        self::assertStringContainsString(CartProgress::class, $template);
        self::assertStringContainsString('getJsonPayload()', $template);
        self::assertStringContainsString('getSectionName()', $template);
    }

    private function cartLayout(): string
    {
        return (string)file_get_contents(
            $this->moduleDir() . '/view/frontend/layout/hyva_checkout_cart_index.xml'
        );
    }

    private function baseCartLayout(): string
    {
        return (string)file_get_contents(
            $this->moduleDir() . '/../DynamicCartProgress/view/frontend/layout/checkout_cart_index.xml'
        );
    }

    public function testLayoutBindsTheSharedViewModelAboveTheCartItems(): void
    {
        $layout = $this->cartLayout();

        self::assertStringContainsString('name="checkout.cart.form.before"', $layout);
        self::assertStringContainsString('MageEnv\DynamicCartProgress\ViewModel\CartProgress', $layout);
        self::assertStringContainsString('MageEnv_DynamicCartProgressHyva::cart/progress.phtml', $layout);
        self::assertStringContainsString('before="-"', $layout);
    }

    public function testHyvaCartBlockNameIsDistinctFromTheBaseModule(): void
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($this->cartLayout()), 'Hyva cart layout XML did not parse.');

        $xpath = new \DOMXPath($document);
        $blocks = $xpath->query('//referenceContainer[@name="checkout.cart.form.before"]/block');

        self::assertSame(1, $blocks->length, 'Expected exactly one Hyva cart-page block.');

        /** @var \DOMElement $block */
        $block = $blocks->item(0);
        self::assertSame('mageenv.dcp.cart.progress.hyva', $block->getAttribute('name'));
        self::assertSame(
            'MageEnv_DynamicCartProgressHyva::cart/progress.phtml',
            $block->getAttribute('template')
        );
        self::assertDoesNotMatchRegularExpression('/name="mageenv\.dcp\.cart\.progress"/', $this->cartLayout());
    }

    public function testBaseAndHyvaCartLayoutsNeverShareABlockName(): void
    {
        $names = static function (string $xml): array {
            $document = new \DOMDocument();
            $document->loadXML($xml);
            $xpath = new \DOMXPath($document);
            $found = [];
            foreach ($xpath->query('//block[@name]') as $node) {
                /** @var \DOMElement $node */
                $found[] = $node->getAttribute('name');
            }

            return $found;
        };

        $baseNames = $names($this->baseCartLayout());
        $hyvaNames = $names($this->cartLayout());

        self::assertContains('mageenv.dcp.cart.progress', $baseNames, 'Base module must keep its block name.');
        self::assertContains('mageenv.dcp.cart.progress.hyva', $hyvaNames);
        self::assertSame([], array_intersect($baseNames, $hyvaNames));
    }

    public function testMinicartLayoutNamesAreUnchangedAndDistinct(): void
    {
        $hyvaDefault = (string)file_get_contents($this->moduleDir() . '/view/frontend/layout/hyva_default.xml');
        $baseDefault = (string)file_get_contents(
            $this->moduleDir() . '/../DynamicCartProgress/view/frontend/layout/default.xml'
        );

        self::assertStringContainsString('name="mageenv.dcp.minicart.progress"', $hyvaDefault);
        self::assertStringContainsString('name="mageenv_dcp_minicart_progress"', $baseDefault);
        self::assertStringNotContainsString('mageenv.dcp.minicart.progress', $baseDefault);
        self::assertStringNotContainsString('mageenv_dcp_minicart_progress', $hyvaDefault);
    }

    public function testAlpineUsesPrivateContentLoadedNotKnockout(): void
    {
        $code = $this->componentCode();

        self::assertStringContainsString("addEventListener('private-content-loaded'", $code);
        self::assertStringNotContainsString('customerData', $code);
        self::assertStringNotContainsString('ko.observable', $code);
        self::assertStringNotContainsString('ko.pureComputed', $code);
        self::assertStringNotContainsString('uiComponent', $code);
        self::assertStringNotContainsString('define([', $code);
        self::assertStringNotContainsString('Magento_Customer/js', $code);
    }

    public function testAlpineReadsOnlyPayloadFields(): void
    {
        preg_match_all('/(?::[a-z-]+|x-[a-z]+)="([^"]*)"/s', $this->template(), $attrs);
        $code = implode("\n", $attrs[1]) . "\n" . $this->factoryJs();

        preg_match_all('/(?:this\.)?state\.([a-zA-Z]+)/', $code, $top);
        foreach (array_unique($top[1]) as $field) {
            self::assertContains($field, self::PAYLOAD_TOP_KEYS, "state.$field is not in the payload");
        }

        preg_match_all('/milestone\.([a-zA-Z]+)/', $code, $ms);
        foreach (array_unique($ms[1]) as $field) {
            self::assertContains($field, self::PAYLOAD_MILESTONE_KEYS, "milestone.$field is not in the payload");
        }
    }

    public function testTheSentenceReadsRewardTextAndAmountsVerbatim(): void
    {
        $js = $this->factoryJs();
        $template = $this->template();

        self::assertStringContainsString("this.label('addMore', 'Add %1 more to unlock %2')", $js);
        self::assertStringContainsString("[next.formattedRemainingAmount, 'amount']", $js);
        self::assertStringContainsString("[next.rewardLabel, 'reward']", $js);
        self::assertStringContainsString('phrase.split(/(%[12])/)', $js);
        self::assertStringContainsString('x-for="(part, index) in messageParts()"', $template);
        self::assertStringContainsString(':class="part.cls" x-text="part.text"', $template);
        self::assertStringContainsString('last.completionMessage', $js);
        self::assertStringContainsString("'addMore' => (string)__('Add %1 more to unlock %2')", $template);

        foreach (['x-html', 'innerHTML', '{{{'] as $needle) {
            self::assertStringNotContainsString($needle, $template . $js);
        }
    }

    public function testRewardCodeIsShownOnlyOnceEarned(): void
    {
        $js = $this->factoryJs();
        $template = $this->template();

        self::assertMatchesRegularExpression('/rewardCode\(\)\s*\{[\s\S]*?this\.securedRewards\(\)/', $js);
        self::assertStringContainsString('<template x-if="rewardCode()">', $template);
        self::assertStringContainsString('x-text="codeValue()"', $template);
        self::assertStringContainsString('@click="copyCode()"', $template);
        self::assertStringContainsString('navigator.clipboard.writeText(milestone.rewardCode)', $js);
    }

    public function testTheBarIsDecorativeAndStyleSelectable(): void
    {
        $template = $this->template();
        $js = $this->factoryJs();

        self::assertStringContainsString('class="mageenv-dcp-cart__meter" aria-hidden="true"', $template);
        self::assertStringContainsString(":style=\"{ '--dcp-fill': fillValue() }\"", $template);
        self::assertStringContainsString("'--dcp-pos': (milestone.positionPercent)", $template);
        foreach ([
            "segmented_bar: 'segmented'",
            "linear_marker: 'linear'",
            "step_indicator: 'step'",
            "simple_fill: 'fill'",
        ] as $style) {
            self::assertStringContainsString($style, $js);
        }
        self::assertStringContainsString(
            "return this.styleKey() === 'fill' ? this.state.nextPercent : this.state.overallPercent;",
            $js
        );
    }

    public function testTheTiersAreAnAccessibleOrderedList(): void
    {
        $template = $this->template();

        self::assertMatchesRegularExpression(
            '/<ol class="mageenv-dcp-cart__legend" :aria-label="labels.tiers">[\s\S]*x-for="row in legend\(\)"/',
            $template
        );
        self::assertMatchesRegularExpression(
            '/<ol class="mageenv-dcp-cart__steps"[\s\S]*?:aria-label="labels.tiers"/',
            $template
        );
        self::assertStringContainsString(":aria-current=\"index === nextIndex() ? 'step' : false\"", $template);
        self::assertStringContainsString('x-text="tierState(milestone, index)"', $template);
        self::assertStringContainsString('LEGEND_DENSE_FROM', $this->factoryJs());
    }

    public function testNothingRendersForNonDisplayableState(): void
    {
        self::assertStringContainsString('x-show="visible()"', $this->template());

        $factory = $this->factoryJs();
        self::assertStringContainsString('Boolean(this.state.displayable)', $factory);
        self::assertStringContainsString('Boolean(this.state.cartPageEnabled)', $factory);
        self::assertStringContainsString('this.milestones().length > 0', $factory);
    }

    public function testNoFrontendArithmeticOrUnitAppending(): void
    {
        preg_match_all('/(?::[a-z-]+|x-[a-z]+)="([^"]*)"/s', $this->template(), $attrs);
        $code = implode("\n", $attrs[1]) . "\n" . $this->factoryJs();

        self::assertDoesNotMatchRegularExpression('/(Amount|Percent)[^,;)\n]*[-*\/]/', $code);
        self::assertDoesNotMatchRegularExpression('/[-*\/][^,;(\n]*(Amount|Percent)\b/', $code);
        self::assertStringNotContainsString("+ '%'", $this->factoryJs());
        self::assertStringNotContainsString("+ '%'", implode(' ', $attrs[1]));
    }

    public function testThemeParityWithLuma(): void
    {
        $lumaJs = (string)file_get_contents(
            $this->moduleDir() . '/../DynamicCartProgress/view/frontend/web/js/view/cart-progress.js'
        );
        $hyva = $this->componentCode();

        $needles = [
            'nextReward', 'securedRewards', 'latestSecured', 'rewardCode', 'phraseParts', 'messageParts',
            'unlockedNote', 'codeLabel', 'copyCode', 'copyLabel', 'styleKey', 'styleClass', 'fillValue',
            'youLabel', 'fillFromLabel', 'fillToParts', 'markClass', 'legend', 'legendClass', 'stepClass',
            'stepsClass', 'tierState', 'LEGEND_DENSE_FROM', 'STEPS_DENSE_FROM',
            'Add %1 more to unlock %2', '%1 unlocked, the top reward on this order', 'Code for %1:', '%1 at %2',
        ];

        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $lumaJs, "Luma cart component missing: $needle");
            self::assertStringContainsString($needle, $hyva, "Hyva cart component missing: $needle");
        }
    }

    public function testAccessibilityIsPreservedAndMotionIsGated(): void
    {
        $template = $this->template();

        // One contextual atomic live region; the ladder is a labelled ordered list.
        self::assertStringContainsString('role="status" aria-atomic="true"', $template);
        self::assertStringContainsString(':aria-label="labels.region"', $template);
        self::assertStringContainsString('<ol', $template);
        self::assertStringContainsString(':aria-label="labels.tiers"', $template);
        self::assertStringContainsString('aria-hidden="true"', $template);
        self::assertStringNotContainsString('progressbar', $template);
        self::assertStringNotContainsString('--dcp-ring', $template);

        $css = $this->css();
        self::assertStringContainsString('prefers-reduced-motion: no-preference', $css);
        self::assertMatchesRegularExpression('/prefers-reduced-motion:\s*reduce[^}]*\{[^}]*transition:\s*none/s', $css);
    }

    /**
     * Regression for m31: no text surface may use the bare accent as its
     * background (--dcp-accent can resolve to currentColor).
     */
    public function testNoTextSurfaceUsesTheBareAccentAsBackground(): void
    {
        $css = $this->css();

        foreach ([
            '.mageenv-dcp-cart {',
            '.mageenv-dcp-cart__msg {',
            '.mageenv-dcp-cart__note {',
            '.mageenv-dcp-minicart__card {',
        ] as $selector) {
            self::assertStringNotContainsString('background: var(--dcp-accent);', $this->cssRuleBody($css, $selector));
        }
        self::assertStringContainsString(
            'color: var(--dcp-surface);',
            $this->cssRuleBody($css, '.mageenv-dcp-cart__you {')
        );
    }

    /**
     * Both stylesheets are generated from one source: after the token block,
     * the Hyva CSS and the Luma LESS (calc() unescaped) carry identical rules.
     */
    public function testStylesheetRulesMatchLuma(): void
    {
        $less = (string)file_get_contents(
            $this->moduleDir() . '/../DynamicCartProgress/view/frontend/web/css/source/_module.less'
        );
        $lumaBody = substr($less, strpos($less, '/* ---------- Shared ---------- */'));
        // Luma-only rules (the phone page gutter) follow the shared body.
        $lumaOnly = strpos($lumaBody, '//  Luma only:');
        if ($lumaOnly !== false) {
            $lumaBody = substr($lumaBody, 0, $lumaOnly);
        }
        $lumaBody = substr($lumaBody, 0, strrpos($lumaBody, '}'));
        $lumaBody = preg_replace('/~"([^"]*)"/', '$1', $lumaBody);

        $css = $this->css();
        $hyvaBody = substr($css, strpos($css, '/* ---------- Shared ---------- */'));

        $normalise = static fn (string $text): string => trim((string)preg_replace('/\s+/', ' ', $text));
        self::assertSame($normalise($lumaBody), $normalise($hyvaBody));
    }

    /**
     * Root cause of the m31 bug: on a Luma store this companion stylesheet AND
     * the base module's compiled _module.less both load. This sheet's token rule
     * must be specificity 0 (:where).
     */
    public function testCompanionTokenRuleDefersToTheBaseStylesheet(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/:where\(\s*\.mageenv-dcp-cart\s*,\s*\.mageenv-dcp-minicart\s*\)\s*\{[^}]*--dcp-accent/s',
            $css,
            'the --dcp-* token rule must be wrapped in :where() to stay at specificity 0'
        );
        self::assertDoesNotMatchRegularExpression(
            '/(^|\})\s*\.mageenv-dcp-cart\s*,\s*\.mageenv-dcp-minicart\s*\{[^}]*--dcp-accent/s',
            $css
        );
    }

    /**
     * The Alpine visibility gate is on the outermost component element itself.
     */
    public function testVisibilityGateIsOnTheComponentRoot(): void
    {
        $template = $this->template();

        $rootOpen = strpos($template, '<div');
        $rootClassPos = strpos($template, 'class="mageenv-dcp-cart mageenv-dcp-cart--');
        self::assertNotFalse($rootOpen);
        self::assertNotFalse($rootClassPos);

        self::assertStringNotContainsString(
            '<div',
            substr($template, $rootOpen + 4, $rootClassPos - $rootOpen - 4),
            'no wrapper <div> may sit between the file start and the gated component root'
        );

        $rootTag = substr($template, $rootOpen, $rootClassPos - $rootOpen);
        self::assertStringContainsString('x-show="visible()"', $rootTag);
        self::assertStringContainsString('x-cloak', $rootTag);
    }

    /**
     * @param string $css
     * @param string $selectorWithBrace
     * @return string
     */
    private function cssRuleBody(string $css, string $selectorWithBrace): string
    {
        $start = strpos($css, $selectorWithBrace);
        self::assertNotFalse($start, "CSS selector not found: $selectorWithBrace");
        $from = $start + strlen($selectorWithBrace);
        $end = strpos($css, '}', $from);
        self::assertNotFalse($end, "CSS rule not closed: $selectorWithBrace");

        return substr($css, $from, $end - $from);
    }
}
