<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Session;

/**
 * Steps for what the application starts with: its environment, terminal features and the files of the scenario's workspace.
 *
 * File paths are relative to the workspace, a directory of the scenario's own
 * that the application finds in TUINK_WORKSPACE and that "%workspace%" names in
 * arguments and variables.
 */
trait WorkspaceStepsTrait
{
    #[Given('/^the environment variable "([^"]+)" is "([^"]*)"$/')]
    public function setEnv(string $name, string $value): void
    {
        $session = $this->getSession();
        $session->setLaunch($session->getLaunch()->withEnv($name, $value));
    }

    #[Given('the terminal supports the kitty keyboard protocol')]
    public function useKittyProtocol(): void
    {
        $session = $this->getSession();
        $session->setLaunch($session->getLaunch()->withKitty());
    }

    #[Given('/^a file "([^"]+)" containing:$/')]
    public function writeFile(string $path, PyStringNode $content): void
    {
        $this
            ->getSession()
            ->getWorkspace()
            ->write($path, $content->getRaw()."\n");
    }

    #[Given('/^a file "([^"]+)" containing "([^"]*)"$/')]
    public function writeFileLine(string $path, string $content): void
    {
        $this->getSession()->getWorkspace()->write($path, $content);
    }

    #[Given('/^an empty file "([^"]+)"$/')]
    public function writeEmptyFile(string $path): void
    {
        $this->getSession()->getWorkspace()->write($path, content: '');
    }

    #[Given('/^a directory "([^"]+)"$/')]
    public function createDirectory(string $path): void
    {
        $this->getSession()->getWorkspace()->createDirectory($path);
    }

    #[When('/^I (?:delete|remove) "([^"]+)"$/')]
    public function remove(string $path): void
    {
        $this->getSession()->getWorkspace()->remove($path);
    }

    #[Then('/^the file "([^"]+)" should (not )?exist$/')]
    public function assertFileExists(string $path, string $not = ''): void
    {
        '' === $not ? $this->getSession()->files()->assertExists($path) : $this->getSession()->files()->assertNotExists($path);
    }

    #[Then('/^the file "([^"]+)" should (not )?contain "([^"]*)"$/')]
    public function assertFileContains(string $path, string $not, string $text): void
    {
        '' === $not ? $this->getSession()->files()->assertContains($path, $text) : $this->getSession()->files()->assertNotContains($path, $text);
    }

    #[Then('/^the file "([^"]+)" should contain exactly:$/')]
    public function assertFileEquals(string $path, PyStringNode $content): void
    {
        $this->getSession()->files()->assertEquals($path, $content->getRaw());
    }

    abstract protected function getSession(): Session;
}
