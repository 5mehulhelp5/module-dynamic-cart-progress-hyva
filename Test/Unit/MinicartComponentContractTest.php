<?php
/**
 * Copyright © MageEnv. All rights reserved.
 * Licensed under the Open Software License 3.0 (OSL-3.0). See LICENSE.txt.
 */
declare(strict_types=1);

namespace MageEnv\DynamicCartProgressHyva\Test\Unit;

use MageEnv\DynamicCartProgress\Api\Data\ProgressStateInterface;
use MageEnv\DynamicCartProgress\Model\Progress\ProgressStateSerializer;
use MageEnv\DynamicCartProgress\ViewModel\CartProgress;
use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for the Hyva mini cart Alpine component - "The Offer"
 * at drawer scale (Phase 9B).
 *
 * Verifies theme parity with Luma and that the Alpine island consumes only the
 * shared payload / shared ViewModel - no second engine, no second payload, no
 * percentage rendered.
 */
class MinicartComponentContractTest extends TestCase
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
            $this->moduleDir() . '/view/frontend/templates/minicart/progress.phtml'
        );
    }

    /**
     * Phase 7G: the Alpine factory now lives in an external, CSP-safe JS file.
     */
    private function factoryJs(): string
    {
        return (string)file_get_contents(
            $this->moduleDir() . '/view/frontend/web/js/minicart-progress.js'
        );
    }

    private function componentCode(): string
    {
        return $this->template() . "\n" . $this->factoryJs();
    }

    public function testTemplateUsesTheSharedViewModel(): void
    {
        $template = $this->template();

        self::assertStringContainsString(CartProgress::class, $template);
        self::assertStringContainsString('getJsonPayload()', $template);
        self::assertStringContainsString('getSectionName()', $template);
    }

    public function testLayoutBindsTheSharedViewModelToTheMinicart(): void
    {
        $layout = (string)file_get_contents($this->moduleDir() . '/view/frontend/layout/hyva_default.xml');

        // The Hyva default theme's mini cart is the "cart-drawer" block; extensions
        // render through its always-present "cart-drawer.items.before" container.
        // A reference to a "minicart" block silently renders nothing on Hyva.
        self::assertStringContainsString('<referenceContainer name="cart-drawer.items.before">', $layout);
        self::assertStringNotContainsString('referenceBlock name="minicart"', $layout);
        self::assertStringContainsString(
            'MageEnv\DynamicCartProgress\ViewModel\CartProgress',
            $layout
        );
        self::assertStringContainsString('MageEnv_DynamicCartProgressHyva::minicart/progress.phtml', $layout);
    }

    public function testAlpineUsesPrivateContentLoadedNotKnockoutCustomerData(): void
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

    public function testAlpineOnlyReadsPayloadTopLevelFields(): void
    {
        preg_match_all('/(?:this\.)?state\.([a-zA-Z]+)/', $this->componentCode(), $matches);
        $allowed = self::PAYLOAD_TOP_KEYS;

        foreach (array_unique($matches[1]) as $field) {
            self::assertContains(
                $field,
                $allowed,
                sprintf('Alpine component reads unknown payload field "state.%s"', $field)
            );
        }
    }

    public function testAlpineOnlyReadsPayloadMilestoneFields(): void
    {
        preg_match_all('/milestone\.([a-zA-Z]+)/', $this->componentCode(), $matches);

        foreach (array_unique($matches[1]) as $field) {
            self::assertContains(
                $field,
                self::PAYLOAD_MILESTONE_KEYS,
                sprintf('Alpine component reads unknown milestone field "milestone.%s"', $field)
            );
        }
    }

    public function testNoFrontendArithmeticOrUnitAppending(): void
    {
        preg_match_all('/(?::[a-z-]+|x-[a-z]+)="([^"]*)"/s', $this->template(), $attrs);
        $factory = $this->factoryJs();
        $code = implode("\n", $attrs[1]) . "\n" . $factory;

        self::assertDoesNotMatchRegularExpression('/(Amount|Percent)[^,;)\n]*[-*\/]/', $code);
        self::assertDoesNotMatchRegularExpression('/[-*\/][^,;(\n]*(Amount|Percent)\b/', $code);
        // The drawer renders no percentage text: no "%" unit is appended.
        self::assertStringNotContainsString("+ '%'", $factory);
        self::assertStringNotContainsString("+ '%'", implode(' ', $attrs[1]));
        // The Measure's only *Percent reads are proportional positions handed
        // straight to --dcp-pos / --dcp-next, parenthesised so they are never
        // combined.
        preg_match_all("/'--dcp-(?:pos|next|seg)':\s*\((.*?)\)\s*[,}]/s", $this->template(), $posArgs);
        self::assertNotEmpty($posArgs[1]);
        foreach ($posArgs[1] as $arg) {
            self::assertContains(
                trim($arg),
                [
                    'milestone.positionPercent',
                    'state.overallPercent',
                    'state.nextPercent',
                    'nextReward()?.positionPercent',
                ],
                "a progress-visual custom property was given a computed expression: $arg"
            );
        }
    }

    /**
     * The Reward Card at a fixed height: the reward first, then one progress
     * line in the campaign's style. Decorative parts are hidden from assistive
     * tech; the milestone marks are buttons.
     */
    public function testTheCardLeadsWithTheRewardAndTheLineIsStyleSelectable(): void
    {
        $template = $this->template();
        $factory = $this->factoryJs();

        self::assertStringContainsString('class="mageenv-dcp-minicart__card"', $template);
        self::assertStringContainsString('x-text="offerLabel()"', $template);
        self::assertStringContainsString('x-text="distanceLabel()"', $template);
        self::assertGreaterThan(
            strpos($template, 'mageenv-dcp-minicart__offer'),
            strpos($template, 'mageenv-dcp-minicart__meter'),
            'The reward must come before the progress line.'
        );
        self::assertStringContainsString(':class="styleClass()"', $template);
        self::assertMatchesRegularExpression('/__fill"\s+aria-hidden="true"/', $template);
        self::assertMatchesRegularExpression('/__you"\s+aria-hidden="true"/', $template);
        self::assertStringContainsString("'--dcp-pos': (milestone.positionPercent)", $template);

        self::assertStringContainsString("PREFIX + '__meter--' + this.styleKey()", $factory);
        self::assertStringContainsString("DEFAULT_STYLE = 'segmented_bar'", $factory);
        self::assertStringContainsString(
            "return this.styleKey() === 'fill' ? this.state.nextPercent : this.state.overallPercent;",
            $factory
        );

        // An earned code replaces the secured count and copies on click.
        self::assertStringContainsString('<template x-if="rewardCode()">', $template);
        self::assertStringContainsString('@click="copyCode()" :aria-label="codeAria()"', $template);
        self::assertMatchesRegularExpression('/rewardCode\(\)\s*\{[\s\S]*?this\.securedRewards\(\)/', $factory);
    }

    public function testEachMilestoneIsAnAccessibleTriggerWithDetail(): void
    {
        $template = $this->template();
        $factory = $this->factoryJs();

        self::assertMatchesRegularExpression(
            '/<button\s+type="button"\s+class="mageenv-dcp-minicart__ms"/s',
            $template
        );
        self::assertStringContainsString(':aria-label="milestoneAria(milestone)"', $template);
        self::assertMatchesRegularExpression('/__tip"\s+aria-hidden="true"/', $template);
        self::assertStringContainsString('x-text="milestone.rewardLabel"', $template);
        self::assertStringContainsString('x-text="milestoneReach(milestone)"', $template);
        self::assertStringContainsString('x-text="milestoneStatus(milestone)"', $template);
        self::assertStringNotContainsString('x-html', $template);
        self::assertStringContainsString(':class="msClass(milestone, index)"', $template);
        self::assertStringContainsString("[PREFIX + '__ms--secured']: milestone.achieved", $factory);
        self::assertMatchesRegularExpression('/milestoneReach\(milestone\)\s*\{/', $factory);
    }

    public function testDrawerParityWithLuma(): void
    {
        $lumaJs = (string)file_get_contents(
            $this->moduleDir() . '/../DynamicCartProgress/view/frontend/web/js/view/minicart-progress.js'
        );
        $hyva = $this->componentCode();

        $needles = [
            'nextReward', 'rewardCode', 'kickerLabel', 'securedNote', 'codeBadgeLabel', 'codeAria', 'copyCode',
            'offerLabel', 'distanceLabel', 'thenLabel', 'progressStyle', 'styleKey', 'styleClass', 'fillValue',
            'msClass', 'stepClass', 'milestoneReach', 'milestoneStatus', 'milestoneAria', 'DENSE_FROM',
            'Just %1 away', 'then %1', 'Every reward secured', 'Copy code %1',
        ];
        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $lumaJs, "Luma drawer missing: $needle");
            self::assertStringContainsString($needle, $hyva, "Hyva drawer missing: $needle");
        }
    }

    public function testSharedContractClassesExist(): void
    {
        self::assertTrue(interface_exists(ProgressStateInterface::class));
        self::assertTrue(class_exists(ProgressStateSerializer::class));
        self::assertTrue(class_exists(CartProgress::class));
    }
}
