<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Install\Tests\Functional\Updates;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Install\Updates\BackendGroupsExplicitAllowDenyMigration;
use TYPO3\CMS\Install\Updates\IndexedSearchCTypeMigration;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class IndexedSearchCTypeMigrationTest extends FunctionalTestCase
{
    protected const TABLE_CONTENT = 'tt_content';
    protected const TABLE_BACKEND_USER_GROUPS = 'be_groups';

    protected string $baseDataSet = __DIR__ . '/Fixtures/IndexedSearchBase.csv';
    protected string $fullMigrationResultDataSet = __DIR__ . '/Fixtures/IndexedSearchMigrated.csv';
    protected string $partiallyMigrationResultDataSet = __DIR__ . '/Fixtures/IndexedSearchPartiallyMigrated.csv';

    /**
     * @test
     */
    public function contentElementsAndBackendUserGroupsUpdated(): void
    {
        $registryMock = $this->createMock(Registry::class);
        $registryMock
            ->method('get')
            ->with('installUpdate', BackendGroupsExplicitAllowDenyMigration::class, false)
            ->willReturn(true);

        $subject = new IndexedSearchCTypeMigration($registryMock, $this->get(ConnectionPool::class));

        $this->importCSVDataSet($this->baseDataSet);
        self::assertTrue($subject->updateNecessary());
        $subject->executeUpdate();
        self::assertFalse($subject->updateNecessary());
        $this->assertCSVDataSet($this->fullMigrationResultDataSet);

        // Just ensure that running the upgrade again does not change anything
        $subject->executeUpdate();
        $this->assertCSVDataSet($this->fullMigrationResultDataSet);
    }

    /**
     * @test
     */
    public function backendUserGroupsNotUpdated(): void
    {
        $registryMock = $this->createMock(Registry::class);
        $registryMock
            ->method('get')
            ->with('installUpdate', BackendGroupsExplicitAllowDenyMigration::class, false)
            ->willReturn(false);

        $subject = new IndexedSearchCTypeMigration($registryMock, $this->get(ConnectionPool::class));

        $this->importCSVDataSet($this->baseDataSet);
        self::assertTrue($subject->updateNecessary());
        $subject->executeUpdate();
        self::assertFalse($subject->updateNecessary());
        $this->assertCSVDataSet($this->partiallyMigrationResultDataSet);

        // Just ensure that running the upgrade again does not change anything
        $subject->executeUpdate();
        $this->assertCSVDataSet($this->partiallyMigrationResultDataSet);
    }
}
