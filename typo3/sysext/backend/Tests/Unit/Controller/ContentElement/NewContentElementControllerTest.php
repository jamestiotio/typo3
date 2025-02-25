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

namespace TYPO3\CMS\Backend\Tests\Unit\Controller\ContentElement;

use TYPO3\CMS\Backend\Controller\ContentElement\NewContentElementController;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class NewContentElementControllerTest extends UnitTestCase
{
    /**
     * @test
     */
    public function migrateCommonGroupToDefaultTest(): void
    {
        $input = [
            'common.' => [
                'elements.' => [
                    'c_element.' => [
                        'title' => 'foo',
                    ],
                ],
                'removeItems' => 'foo,bar',
            ],
            'default.' => [
                'elements.' => [
                    'd_element.' => [
                        'title' => 'bar',
                        'tt_content_defValues' => [
                            'field' => 'value',
                        ],
                    ],
                ],
                'removeItems' => 'baz',
            ],
            'custom_group.' => [
                'elements.' => [
                    'custom_element' => [
                        'title' => 'i will be migrated',
                        'saveAndClose' => true,
                        'tt_content_defValues' => [
                            'field' => 'value',
                        ],
                    ],
                ],
                'removeItems' => 'some_element',
            ],
            'removeItems' => 'forms',
        ];

        $expected = [
            'default.' => [
                'elements.' => [
                    'd_element.' => [
                        'title' => 'bar',
                        'tt_content_defValues' => [
                            'field' => 'value',
                        ],
                    ],
                    'c_element.' => [
                        'title' => 'foo',
                    ],
                ],
                'removeItems' => [
                    'baz',
                    'foo',
                    'bar',
                ],
            ],
            'custom_group.' => [
                'elements.' => [
                    'custom_element' => [
                        'title' => 'i will be migrated',
                        'saveAndClose' => true,
                        'tt_content_defValues' => [
                            'field' => 'value',
                        ],
                    ],
                ],
                'removeItems' => 'some_element',
            ],
            'removeItems' => 'forms',
        ];

        $result = (new \ReflectionClass(NewContentElementController::class))
            ->getMethod('migrateCommonGroupToDefault')
            ->invokeArgs($this->createMock(NewContentElementController::class), [$input]);

        self::assertSame($expected, $result);
    }
    /**
     * @test
     */
    public function removeWizardsByPageTsTest(): void
    {
        $wizards = [
            'default.' => [
                'elements.' => [
                    'header.' => [
                        'title' => 'header',
                    ],
                    'text.' => [
                        'title' => 'text',
                    ],
                    'image.' => [
                        'title' => 'image',
                    ],
                    'textmedia.' => [
                        'title' => 'textmedia',
                    ],
                ],
            ],
            'lists.' => [
                'elements.' => [
                    'table.' => [
                        'title' => 'table',
                    ],
                ],
            ],
            'menu.' => [
                'elements.' => [
                    'menu_abstract.' => [
                        'title' => 'menuabstract',
                    ],
                ],
            ],
            'special.' => [
                'elements.' => [
                    'html.' => [
                        'title' => 'html',
                    ],
                ],
            ],
        ];

        $wizardsTsConfig = [
            'wizardItems.' => [
                'default.' => [
                    'elements.' => [],
                    'removeItems' => [
                        'text',
                        'image',
                    ],
                ],
                'removeItems' => 'lists,special',
            ],
        ];

        $expected = $wizards;
        unset($expected['default.']['elements.']['text.']);
        unset($expected['default.']['elements.']['image.']);
        unset($expected['lists.']);
        unset($expected['special.']);

        $result = (new \ReflectionClass(NewContentElementController::class))
            ->getMethod('removeWizardsByPageTs')
            ->invokeArgs($this->createMock(NewContentElementController::class), [$wizards, $wizardsTsConfig]);

        self::assertSame($expected, $result);
    }
}
