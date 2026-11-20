<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Workspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkspaceTest extends TestCase
{
    private ?Workspace $workspace = null;
    private ?string $parent = null;

    protected function tearDown(): void
    {
        $this->workspace?->destroy();
        if (null !== $this->parent) {
            \exec('rm -rf '.\escapeshellarg($this->parent));
        }
    }

    public function testCreateMakesAPrivateDirectoryWithItsResolvedPath(): void
    {
        $workspace = $this->workspace();

        $this->assertDirectoryExists($workspace->path);
        $this->assertSame($workspace->path, \realpath($workspace->path));
        $this->assertMatchesRegularExpression('~/tuink-workspace-[0-9a-f]{12}$~', $workspace->path);
        $this->assertSame(0o700, \fileperms($workspace->path) & 0o777);
        $this->assertSame([], \array_values(\array_diff((array) \scandir($workspace->path), ['.', '..'])));
    }

    public function testCreateUnderAParent(): void
    {
        $this->parent = \sys_get_temp_dir().'/tuink-parent-'.\bin2hex(\random_bytes(4));
        $this->workspace = Workspace::create($this->parent.'/');

        $this->assertSame((string) \realpath($this->parent), \dirname($this->workspace->path));
    }

    public function testTwoWorkspacesDiffer(): void
    {
        $other = Workspace::create();
        try {
            $this->assertNotSame($this->workspace()->path, $other->path);
        } finally {
            $other->destroy();
        }
    }

    public function testCreateFailsUnderAFile(): void
    {
        $this->parent = (string) \tempnam(\sys_get_temp_dir(), prefix: 'tuink-file-');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('~^Cannot create the workspace ".+/tuink-workspace-[0-9a-f]+": mkdir\(\): .+$~');
        Workspace::create($this->parent);
    }

    public function testWritingCreatesTheDirectories(): void
    {
        $workspace = $this->workspace();
        $workspace->write('a/b/c.txt', content: 'hello');
        $workspace->write('./d.txt', content: '');

        $this->assertSame('hello', \file_get_contents($workspace->path.'/a/b/c.txt'));
        $this->assertSame('hello', $workspace->read('a/b/c.txt'));
        $this->assertSame('', $workspace->read('d.txt'));
        $this->assertTrue($workspace->exists('a/b'));
        $this->assertFalse($workspace->exists('a/c'));
    }

    public function testReadingWhatIsNotAFile(): void
    {
        $workspace = $this->workspace();
        $workspace->createDirectory('dir/sub');

        $this->assertDirectoryExists($workspace->path.'/dir/sub');
        $this->assertNull($workspace->read('dir'));
        $this->assertNull($workspace->read('missing.txt'));
        $workspace->createDirectory('dir/sub');
        $this->assertDirectoryExists($workspace->path.'/dir/sub');
    }

    public function testReadingAnUnreadableFile(): void
    {
        $workspace = $this->workspace();
        $workspace->write('secret.txt', content: 'x');
        \chmod($workspace->path.'/secret.txt', permissions: 0);
        if (\is_readable($workspace->path.'/secret.txt')) {
            $this->markTestSkipped('Root reads any file.');
        }

        $this->assertNull($workspace->read('secret.txt'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outsidePaths(): iterable
    {
        yield 'absolute' => ['/etc/passwd'];
        yield 'parent' => ['../x'];
        yield 'parent inside' => ['a/../../x'];
        yield 'empty' => [''];
        yield 'itself' => ['.'];
        yield 'slashes and dots' => ['./'];
    }

    #[DataProvider('outsidePaths')]
    public function testPathsOutsideTheWorkspace(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('"%s" is not a path inside the workspace; use a relative path without "..".', $path));

        $this->workspace()->resolve($path);
    }

    public function testResolve(): void
    {
        $workspace = $this->workspace();

        $this->assertSame($workspace->path.'/a/b', $workspace->resolve('a/b'));
        $this->assertSame($workspace->path.'/..a', $workspace->resolve('..a'));
    }

    public function testWritingWhereADirectoryIs(): void
    {
        $workspace = $this->workspace();
        $workspace->createDirectory('taken');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('~^Cannot write "taken": file_put_contents\(.+\): Failed to open stream: Is a directory$~');
        $workspace->write('taken', content: 'x');
    }

    public function testADirectoryWhereAFileIs(): void
    {
        $workspace = $this->workspace();
        $workspace->write('file', content: 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('~^Cannot create the directory "file/sub": mkdir\(\): .+$~');
        $workspace->write('file/sub/x.txt', content: 'x');
    }

    public function testRemoving(): void
    {
        $workspace = $this->workspace();
        $workspace->write('a/b/c.txt', content: 'x');
        $workspace->write('d.txt', content: 'x');
        \symlink($workspace->path.'/a', $workspace->path.'/link');

        $workspace->remove('link');
        $this->assertFileDoesNotExist($workspace->path.'/link');
        $this->assertFileExists($workspace->path.'/a/b/c.txt');

        $workspace->remove('a');
        $workspace->remove('d.txt');
        $this->assertSame([], \array_values(\array_diff((array) \scandir($workspace->path), ['.', '..'])));
    }

    public function testRemovingADanglingLink(): void
    {
        $workspace = $this->workspace();
        \symlink($workspace->path.'/nowhere', $workspace->path.'/link');

        $workspace->remove('link');

        $this->assertFalse(\is_link($workspace->path.'/link'));
    }

    public function testRemovingWhatDoesNotExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"missing" does not exist in the workspace.');

        $this->workspace()->remove('missing');
    }

    public function testDestroy(): void
    {
        $workspace = $this->workspace();
        $workspace->write('a/b.txt', content: 'x');

        $workspace->destroy();
        $this->assertDirectoryDoesNotExist($workspace->path);
        // Twice is harmless
        $workspace->destroy();
        $this->assertDirectoryDoesNotExist($workspace->path);
    }

    private function workspace(): Workspace
    {
        return $this->workspace ??= Workspace::create();
    }
}
