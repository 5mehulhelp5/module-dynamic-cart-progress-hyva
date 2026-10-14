<?php
/**
 * Copyright © MageEnv. All rights reserved.
 * Licensed under the Open Software License 3.0 (OSL-3.0). See LICENSE.txt.
 */
declare(strict_types=1);

namespace MageEnv\DynamicCartProgressHyva\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Hyva cart layout declares one core-gated block per Cart Page Position.
 * Hyva prints the Summary heading in its wrapper template, so "top" must be
 * first in cart.summary (a missing sibling would send the block to the end).
 */
class CartPagePositionLayoutContractTest extends TestCase
{
    public function testOneGatedBlockPerPositionInTheExpectedSlot(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/view/frontend/layout/hyva_checkout_cart_index.xml');
        $expected = [
            'above_items' => ['checkout.cart.form.before', 'before', '-'],
            'summary_top' => ['cart.summary', 'before', '-'],
            'summary_below_totals' => ['cart.summary', 'after', 'checkout.cart.totals.container'],
        ];

        $found = [];
        foreach ($xml->xpath('//referenceContainer/block') as $block) {
            $position = (string)$block->xpath('arguments/argument[@name="position"]')[0];
            [$slot, $orderAttr, $sibling] = $expected[$position];

            self::assertSame(
                'MageEnv\DynamicCartProgress\Block\Cart\Progress',
                ltrim((string)$block['class'], '\\'),
                $position
            );
            self::assertSame($slot, (string)$block->xpath('..')[0]['name'], $position);
            self::assertSame($sibling, (string)$block[$orderAttr], $position);
            self::assertStringStartsWith('mageenv.dcp.cart.progress.hyva', (string)$block['name']);
            self::assertSame('MageEnv_DynamicCartProgressHyva::cart/progress.phtml', (string)$block['template']);
            self::assertSame(
                'MageEnv\DynamicCartProgress\ViewModel\CartProgress',
                (string)$block->xpath('arguments/argument[@name="view_model"]')[0]
            );
            $found[] = $position;
        }

        self::assertSame(array_keys($expected), $found);
    }
}
