<?php

namespace craft\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\MigrationHelper;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use yii\base\InvalidParamException;
use yii\db\Expression;

/**
 * m160807_144858_sites migration.
 */
class m160807_144858_sites extends Migration
{
    // Properties
    // =========================================================================

    /**
     * @var array The site FK columns ([table, column, not null?, locale column])
     */
    protected $siteColumns = [
        ['{{%categorygroups_i18n}}', 'siteId', true, 'locale'],
        ['{{%content}}', 'siteId', true, 'locale'],
        ['{{%elements_i18n}}', 'siteId', true, 'locale'],
        ['{{%entrydrafts}}', 'siteId', true, 'locale'],
        ['{{%entryversions}}', 'siteId', true, 'locale'],
        ['{{%matrixblocks}}', 'ownerSiteId', false, 'ownerLocale'],
        ['{{%relations}}', 'sourceSiteId', false, 'sourceLocale'],
        ['{{%routes}}', 'siteId', false, 'locale'],
        ['{{%searchindex}}', 'siteId', true, 'locale'],
        ['{{%sections_i18n}}', 'siteId', true, 'locale'],
        ['{{%templatecaches}}', 'siteId', true, 'locale'],
    ];

    /**
     * @var string The CASE SQL used to set site column values
     */
    protected $caseSql;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Create the sites table
        // ---------------------------------------------------------------------

        $this->createTable('{{%sites}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'language' => $this->string(12)->notNull(),
            'hasUrls' => $this->boolean()->unsigned(),
            'baseUrl' => $this->string(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex($this->db->getIndexName('{{%sites}}', 'handle', true), '{{%sites}}', 'handle', true);
        $this->createIndex($this->db->getIndexName('{{%sites}}', 'sortOrder', false), '{{%sites}}', 'sortOrder', false);

        // Populate based on existing locales
        // ---------------------------------------------------------------------

        $siteInfo = (new Query())
            ->select(['siteName', 'siteUrl'])
            ->from(['{{%info}}'])
            ->one();

        $locales = (new Query())
            ->select(['locale'])
            ->from(['{{%locales}}'])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->column();

        $siteIdsByLocale = [];
        $this->caseSql = 'case';
        $languageCaseSql = 'case';
        $localePermissions = [];
        $permissionsCaseSql = 'case';

        foreach ($locales as $i => $locale) {
            $siteHandle = $this->locale2handle($locale);
            $language = $this->locale2language($locale);

            $this->insert('{{%sites}}', [
                'name' => $siteInfo['siteName'],
                'handle' => $siteHandle,
                'language' => $language,
                'hasUrls' => 1,
                'baseUrl' => $siteInfo['siteUrl'],
                'sortOrder' => $i + 1,
            ]);

            $siteId = $this->db->getLastInsertID();
            $siteIdsByLocale[$locale] = $siteId;

            $this->caseSql .= ' when % = '.$this->db->quoteValue($locale).' then '.$this->db->quoteValue($siteId);
            $languageCaseSql .= ' when [[language]] = '.$this->db->quoteValue($locale).' then '.$this->db->quoteValue($language);

            $localePermission = 'editlocale:'.$locale;
            $sitePermission = 'editsite:'.$siteId;
            $localePermissions[] = $localePermission;
            $permissionsCaseSql .= ' when [[name]] = '.$this->db->quoteValue($localePermission).' then '.$this->db->quoteValue($sitePermission);
        }

        $this->caseSql .= ' end';
        $languageCaseSql .= ' end';
        $permissionsCaseSql .= ' end';

        // Update the user permissions
        // ---------------------------------------------------------------------

        $this->update(
            '{{%userpermissions}}',
            [
                'name' => new Expression($permissionsCaseSql),
            ],
            ['name' => $localePermissions],
            [],
            false);

        // Create the FK columns
        // ---------------------------------------------------------------------

        foreach ($this->siteColumns as $columnInfo) {
            list($table, $column, $isNotNull, $localeColumn) = $columnInfo;
            $this->addSiteColumn($table, $column, $isNotNull, $localeColumn);
        }

        // Create the new indexes
        // ---------------------------------------------------------------------

        $this->createIndex($this->db->getIndexName('{{%categorygroups_i18n}}', 'groupId,siteId', true), '{{%categorygroups_i18n}}', 'groupId,siteId', true);
        $this->createIndex($this->db->getIndexName('{{%categorygroups_i18n}}', 'siteId', false), '{{%categorygroups_i18n}}', 'siteId', false);
        $this->createIndex($this->db->getIndexName('{{%content}}', 'elementId,siteId', true), '{{%content}}', 'elementId,siteId', true);
        $this->createIndex($this->db->getIndexName('{{%content}}', 'siteId', false), '{{%content}}', 'siteId', false);
        $this->createIndex($this->db->getIndexName('{{%elements_i18n}}', 'elementId,siteId', true), '{{%elements_i18n}}', 'elementId,siteId', true);
        $this->createIndex($this->db->getIndexName('{{%elements_i18n}}', 'uri,siteId', true), '{{%elements_i18n}}', 'uri,siteId', true);
        $this->createIndex($this->db->getIndexName('{{%elements_i18n}}', 'siteId', false), '{{%elements_i18n}}', 'siteId', false);
        $this->createIndex($this->db->getIndexName('{{%elements_i18n}}', 'slug,siteId', false), '{{%elements_i18n}}', 'slug,siteId', false);
        $this->createIndex($this->db->getIndexName('{{%entrydrafts}}', 'entryId,siteId', false), '{{%entrydrafts}}', 'entryId,siteId', false);
        $this->createIndex($this->db->getIndexName('{{%entrydrafts}}', 'siteId', false), '{{%entrydrafts}}', 'siteId', false);
        $this->createIndex($this->db->getIndexName('{{%entryversions}}', 'entryId,siteId', false), '{{%entryversions}}', 'entryId,siteId', false);
        $this->createIndex($this->db->getIndexName('{{%entryversions}}', 'siteId', false), '{{%entryversions}}', 'siteId', false);
        $this->createIndex($this->db->getIndexName('{{%matrixblocks}}', 'ownerSiteId', false), '{{%matrixblocks}}', 'ownerSiteId', false);
        $this->createIndex($this->db->getIndexName('{{%relations}}', 'fieldId,sourceId,sourceSiteId,targetId', true), '{{%relations}}', 'fieldId,sourceId,sourceSiteId,targetId', true);
        $this->createIndex($this->db->getIndexName('{{%relations}}', 'sourceSiteId', false), '{{%relations}}', 'sourceSiteId', false);
        $this->createIndex($this->db->getIndexName('{{%routes}}', 'siteId', false), '{{%routes}}', 'siteId', false);
        $this->createIndex($this->db->getIndexName('{{%sections_i18n}}', 'sectionId,siteId', true), '{{%sections_i18n}}', 'sectionId,siteId', true);
        $this->createIndex($this->db->getIndexName('{{%sections_i18n}}', 'siteId', false), '{{%sections_i18n}}', 'siteId', false);
        $this->createIndex($this->db->getIndexName('{{%templatecaches}}', 'expiryDate,cacheKey,siteId,path', false), '{{%templatecaches}}', 'expiryDate,cacheKey,siteId,path', false);
        $this->createIndex($this->db->getIndexName('{{%templatecaches}}', 'siteId', false), '{{%templatecaches}}', 'siteId', false);

        // Create the new FKs
        // ---------------------------------------------------------------------

        $this->addForeignKey($this->db->getForeignKeyName('{{%categorygroups_i18n}}', 'siteId'), '{{%categorygroups_i18n}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%content}}', 'siteId'), '{{%content}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%elements_i18n}}', 'siteId'), '{{%elements_i18n}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%entrydrafts}}', 'siteId'), '{{%entrydrafts}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%entryversions}}', 'siteId'), '{{%entryversions}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%matrixblocks}}', 'ownerSiteId'), '{{%matrixblocks}}', 'ownerSiteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%relations}}', 'sourceSiteId'), '{{%relations}}', 'sourceSiteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%routes}}', 'siteId'), '{{%routes}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%sections_i18n}}', 'siteId'), '{{%sections_i18n}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey($this->db->getForeignKeyName('{{%templatecaches}}', 'siteId'), '{{%templatecaches}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');

        // Update the searchindex PK
        // ---------------------------------------------------------------------

        $searchTable = $this->db->getSchema()->getRawTableName('{{%searchindex}}');
        $this->execute('alter table '.$this->db->quoteTableName($searchTable).' drop primary key, add primary key(elementId, attribute, fieldId, siteId)');

        // Drop the old FKs
        // ---------------------------------------------------------------------

        MigrationHelper::dropForeignKeyIfExists('{{%categorygroups_i18n}}', ['locale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%content}}', ['locale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%elements_i18n}}', ['locale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%entrydrafts}}', ['locale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%entryversions}}', ['locale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%matrixblocks}}', ['ownerLocale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%relations}}', ['sourceLocale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%routes}}', ['locale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%sections_i18n}}', ['locale'], $this);
        MigrationHelper::dropForeignKeyIfExists('{{%templatecaches}}', ['locale'], $this);

        // Drop the old indexes
        // ---------------------------------------------------------------------

        MigrationHelper::dropIndexIfExists('{{%categorygroups_i18n}}', [
            'groupId',
            'locale'
        ], true, $this);
        MigrationHelper::dropIndexIfExists('{{%categorygroups_i18n}}', ['locale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%content}}', [
            'elementId',
            'locale'
        ], true, $this);
        MigrationHelper::dropIndexIfExists('{{%content}}', ['locale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%elements_i18n}}', [
            'elementId',
            'locale'
        ], true, $this);
        MigrationHelper::dropIndexIfExists('{{%elements_i18n}}', [
            'uri',
            'locale'
        ], true, $this);
        MigrationHelper::dropIndexIfExists('{{%elements_i18n}}', ['locale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%elements_i18n}}', [
            'slug',
            'locale'
        ], false, $this);
        MigrationHelper::dropIndexIfExists('{{%entrydrafts}}', [
            'entryId',
            'locale'
        ], false, $this);
        MigrationHelper::dropIndexIfExists('{{%entrydrafts}}', ['locale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%entryversions}}', [
            'entryId',
            'locale'
        ], false, $this);
        MigrationHelper::dropIndexIfExists('{{%entryversions}}', ['locale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%matrixblocks}}', ['ownerLocale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%relations}}', [
            'fieldId',
            'sourceId',
            'sourceLocale',
            'targetId'
        ], true, $this);
        MigrationHelper::dropIndexIfExists('{{%relations}}', ['sourceLocale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%routes}}', ['locale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%sections_i18n}}', [
            'sectionId',
            'locale'
        ], true, $this);
        MigrationHelper::dropIndexIfExists('{{%sections_i18n}}', ['locale'], false, $this);
        MigrationHelper::dropIndexIfExists('{{%templatecaches}}', [
            'expiryDate',
            'cacheKey',
            'locale',
            'path'
        ], false, $this);
        MigrationHelper::dropIndexIfExists('{{%templatecaches}}', ['locale'], false, $this);

        // Drop the locale columns
        // ---------------------------------------------------------------------

        foreach ($this->siteColumns as $columnInfo) {
            list($table, , , $localeColumn) = $columnInfo;
            $this->dropColumn($table, $localeColumn);
        }

        // Email Messages
        // ---------------------------------------------------------------------

        MigrationHelper::dropForeignKeyIfExists('{{%emailmessages}}', ['locale'], $this);
        MigrationHelper::renameColumn('{{%emailmessages}}', 'locale', 'language', $this);
        $this->alterColumn('{{%emailmessages}}', 'language', $this->string()->notNull());

        $this->update('{{%emailmessages}}', [
            'language' => new Expression($languageCaseSql),
        ], '', [], false);

        // Matrix content tables
        // ---------------------------------------------------------------------

        $matrixTablePrefix = $this->db->getSchema()->getRawTableName('{{%matrixcontent_}}');

        foreach ($this->db->getSchema()->getTableNames() as $tableName) {
            if (StringHelper::startsWith($tableName, $matrixTablePrefix)) {
                // Add the new siteId column + index
                $this->addSiteColumn($tableName, 'siteId', true, 'locale');
                $this->createIndex($this->db->getIndexName($tableName, 'elementId,siteId'), $tableName, 'elementId,siteId', true);
                $this->addForeignKey($this->db->getForeignKeyName($tableName, 'siteId'), $tableName, 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');

                // Delete the old FK, indexes, and column
                MigrationHelper::dropForeignKeyIfExists($tableName, ['locale'], $this);
                MigrationHelper::dropIndexIfExists($tableName, ['elementId','locale'], true, $this);
                MigrationHelper::dropIndexIfExists($tableName, ['locale'], false, $this);
                $this->dropColumn($tableName, 'locale');
            }
        }

        // Add site FKs to third party tables
        // ---------------------------------------------------------------------

        Craft::$app->getDb()->getSchema()->refresh();
        $fks = MigrationHelper::findForeignKeysTo('{{%locales}}', 'locale');

        foreach ($fks as $fkInfo) {
            // Drop the old FK
            MigrationHelper::dropForeignKey($fkInfo->fk, $this);

            // Add a new *__siteId column + FK for each column in this FK that points to locales.locale
            foreach ($fkInfo->fk->refColumns as $i => $refColumn) {
                if ($refColumn == 'locale') {
                    $table = $fkInfo->table->name;
                    $oldColumn = $fkInfo->fk->columns[$i];
                    $newColumn = $oldColumn.'__siteId';
                    $isNotNull = (stripos($fkInfo->table->columns[$oldColumn]->type, 'not null') !== false);
                    $this->addSiteColumn($table, $newColumn, $isNotNull, $oldColumn);
                    $this->addForeignKey($this->db->getForeignKeyName($table, $newColumn), $table, $newColumn, '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
                }
            }
        }

        // Drop the locales table
        // ---------------------------------------------------------------------

        $this->dropTable('{{%locales}}');

        // Drop the site columns from the info table
        // ---------------------------------------------------------------------

        $this->dropColumn('{{%info}}', 'siteName');
        $this->dropColumn('{{%info}}', 'siteUrl');

        // Modify sections and categorygroups tables
        // ---------------------------------------------------------------------

        $i18nTables = [
            ['primary' => '{{%categorygroups}}', 'i18n' => '{{%categorygroups_i18n}}', 'fk' => 'groupId'],
            ['primary' => '{{%sections}}', 'i18n' => '{{%sections_i18n}}', 'fk' => 'sectionId'],
        ];

        foreach ($i18nTables as $tables) {
            // urlFormat => uriFormat
            $this->renameColumn($tables['i18n'], 'urlFormat', 'uriFormat');

            // Combine the uriFormat and nestedUrlFormat columns
            $results = (new Query())
                ->select(['id', 'uriFormat', 'nestedUrlFormat'])
                ->from([$tables['i18n']])
                ->where([
                    'not',
                    [
                        'or',
                        ['nestedUrlFormat' => null],
                        ['nestedUrlFormat' => ''],
                        '[[nestedUrlFormat]] = [[uriFormat]]'
                    ]
                ])
                ->all();

            foreach ($results as $result) {
                $uriFormat = '{% if object.level == 1 %}'.$result['uriFormat'].'{% else %}'.$result['nestedUrlFormat'].'{% endif %}';
                $this->update($tables['i18n'], ['uriFormat' => $uriFormat], ['id' => $result['id']], [], false);
            }

            // Drop the nestedUrlFormat column
            $this->dropColumn($tables['i18n'], 'nestedUrlFormat');

            // Create hasUrls and template columns in the i18n table
            $this->addColumn($tables['i18n'], 'hasUrls', $this->boolean()->notNull()->defaultValue(true));
            $this->addColumn($tables['i18n'], 'template', $this->string(500));

            // Move the hasUrls and template values into the i18n table
            $results = (new Query())
                ->select(['id', 'hasUrls', 'template'])
                ->from([$tables['primary']])
                ->all();

            foreach ($results as $result) {
                $this->update($tables['i18n'], [
                    'hasUrls' => $result['hasUrls'],
                    'template' => $result['template'],
                ], [$tables['fk'] => $result['id']], [], false);
            }

            // Drop the old hasUrls and template columns
            $this->dropColumn($tables['primary'], 'hasUrls');
            $this->dropColumn($tables['primary'], 'template');
        }

        // Field translation methods
        // ---------------------------------------------------------------------

        $this->addColumn(
            '{{%fields}}',
            'translationMethod',
            $this->enum('translationMethod', ['none', 'language', 'site', 'custom'])->notNull()->defaultValue('none')
        );

        $this->addColumn(
            '{{%fields}}',
            'translationKeyFormat',
            $this->text()
        );

        $this->update(
            '{{%fields}}',
            [
                'translationMethod' => 'site',
            ],
            [
                'translatable' => '1',
            ],
            [], false);

        $this->dropColumn('{{%fields}}', 'translatable');

        // Update Matrix/relationship field settings
        // ---------------------------------------------------------------------

        $fields = (new Query())
            ->select(['id', 'type', 'translationMethod', 'settings'])
            ->from(['{{%fields}}'])
            ->where([
                'type' => [
                    'craft\fields\Matrix',
                    'craft\fields\Assets',
                    'craft\fields\Categories',
                    'craft\fields\Entries',
                    'craft\fields\Tags',
                    'craft\fields\Users'
                ]
            ])
            ->all();

        foreach ($fields as $field) {
            try {
                $settings = Json::decode($field['settings']);
            } catch (InvalidParamException $e) {
                Craft::error('Field '.$field['id'].' ('.$field['type'].') settings were invalid JSON: '.$field['settings']);
                $settings = [];
            }

            $localized = ($field['translationMethod'] == 'site');

            if ($field['type'] == 'craft\fields\Matrix') {
                $settings['localizeBlocks'] = $localized;
            } else {
                $settings['localizeRelations'] = $localized;

                // targetLocale => targetSiteId
                if (!empty($settings['targetLocale'])) {
                    $settings['targetSiteId'] = $siteIdsByLocale[$settings['targetLocale']];
                }
                unset($settings['targetLocale']);
            }

            $this->update(
                '{{%fields}}',
                [
                    'translationMethod' => 'none',
                    'settings' => Json::encode($settings),
                ],
                ['id' => $field['id']],
                [],
                false);
        }

        // Update Recent Entries widgets
        // ---------------------------------------------------------------------

        $this->updateRecentEntriesWidgets($siteIdsByLocale);
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m160807_144858_sites cannot be reverted.\n";

        return false;
    }

    /**
     * Updates the 'locale' setting in Recent Entries widgets
     *
     * @param array $siteIdsByLocale Mapping of site IDs to the locale IDs they used to be
     *
     * @return void
     */
    public function updateRecentEntriesWidgets($siteIdsByLocale)
    {
        // Fetch all the Recent Entries widgets that have a locale setting
        $widgetResults = (new Query())
            ->select(['id', 'settings'])
            ->from(['{{%widgets}}'])
            ->where(['like', 'settings', '"locale":'])
            ->all();

        foreach ($widgetResults as $result) {
            $settings = Json::decode($result['settings']);

            // Just to be sure...
            if (!isset($settings['locale'])) {
                continue;
            }

            if (isset($siteIdsByLocale[$settings['locale']])) {
                $settings['siteId'] = $siteIdsByLocale[$settings['locale']];
            }

            unset($settings['locale']);

            $this->update('{{%widgets}}', ['settings' => Json::encode($settings)], ['id' => $result['id']]);
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates a new siteId column and migrates the locale data over
     *
     * @param string  $table
     * @param string  $column
     * @param boolean $isNotNull
     * @param string  $localeColumn
     */
    protected function addSiteColumn($table, $column, $isNotNull, $localeColumn)
    {
        // Ignore NOT NULL for now
        $type = $this->integer();
        $this->addColumn($table, $column, $type);

        // Set the values
        $this->update($table, [
            $column => new Expression(str_replace('%', "[[{$localeColumn}]]", $this->caseSql))
        ], '', [], false);

        if ($isNotNull) {
            $this->alterColumn($table, $column, $type->notNull());
        }
    }

    /**
     * Returns a site handle based on a given locale.
     *
     * @param string $locale
     *
     * @return string
     */
    protected function locale2handle($locale)
    {
        // Make sure it's a valid handle
        if (!preg_match('/^'.HandleValidator::$handlePattern.'$/', $locale) || in_array(StringHelper::toLowerCase($locale), HandleValidator::$baseReservedWords)) {
            $localeParts = array_filter(preg_split('/[^a-zA-Z0-9]/', $locale));

            // Prefix with a random string so there's no chance of a conflict with other locales
            return StringHelper::randomStringWithChars('abcdefghijklmnopqrstuvwxyz', 7) . ($localeParts ? '_'.implode('_', $localeParts) : '');
        }

        return $locale;
    }

    /**
     * Returns a language code based on a given locale.
     *
     * @param string $locale
     *
     * @return string
     */
    protected function locale2language($locale)
    {
        $foundMatch = false;

        // Get the individual words
        $localeParts = array_filter(preg_split('/[^a-zA-Z]+/', $locale));

        if ($localeParts) {
            $language = $localeParts[0].(isset($localeParts[1]) ? '-'.strtoupper($localeParts[1]) : '');
            $allLanguages = Craft::$app->getI18n()->getAllLocaleIds();

            if (in_array($language, $allLanguages)) {
                $foundMatch = true;
            } else {
                // Find the closest one
                foreach ($allLanguages as $testLanguage) {
                    if (StringHelper::startsWith($testLanguage, $language)) {
                        $language = $testLanguage;
                        $foundMatch = true;
                        break;
                    }
                }
                if (!$foundMatch) {
                    foreach ($allLanguages as $testLanguage) {
                        if (StringHelper::startsWith($testLanguage, $localeParts[0])) {
                            $language = $testLanguage;
                            $foundMatch = true;
                            break;
                        }
                    }
                }
            }
        }

        if (!$foundMatch) {
            $language = 'en-US';
        }

        /** @noinspection PhpUndefinedVariableInspection */
        return $language;
    }
}