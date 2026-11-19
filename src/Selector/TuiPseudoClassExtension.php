<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Selector;

use Symfony\Component\CssSelector\XPath\Extension\AbstractExtension;
use Symfony\Component\CssSelector\XPath\XPathExpr;

/**
 * The pseudo-classes of widgets: :focus and :visible.
 *
 * @internal
 */
final class TuiPseudoClassExtension extends AbstractExtension
{
    public const FOCUS_ATTRIBUTE = 'tuink-focus';
    public const VISIBLE_ATTRIBUTE = 'tuink-visible';

    public function getPseudoClassTranslators(): array
    {
        return [
            'focus' => static fn (XPathExpr $xpath): XPathExpr => $xpath->addCondition('@'.self::FOCUS_ATTRIBUTE),
            'visible' => static fn (XPathExpr $xpath): XPathExpr => $xpath->addCondition('@'.self::VISIBLE_ATTRIBUTE),
        ];
    }

    public function getName(): string
    {
        return 'tuink';
    }
}
