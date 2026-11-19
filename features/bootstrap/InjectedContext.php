<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Behat\Step\Then;
use Janalis\Tuink\Tuink;

/**
 * Gets the manager through its constructor, as a project's own context would.
 */
final class InjectedContext implements Behat\Behat\Context\Context
{
    public function __construct(
        private readonly Tuink $tuink,
    ) {
    }

    #[Then('the status line should say :text')]
    public function statusLine(string $text): void
    {
        $this->tuink->getSession()->assertLineContains(2, $text);
    }
}
