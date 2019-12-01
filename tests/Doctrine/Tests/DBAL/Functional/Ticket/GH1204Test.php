<?php

namespace Doctrine\Tests\DBAL\Functional\Ticket;

use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Tests\DbalFunctionalTestCase;
use function in_array;

/**
 * @group GH-1204
 */
class GH1204Test extends DbalFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $platform = $this->connection->getDatabasePlatform()->getName();

        if (in_array($platform, ['sqlite'])) {
            return;
        }

        $this->markTestSkipped('Related to SQLite only');
    }

    public function testUnsignedIntegerDetection()
    {
        $schemaManager = $this->connection->getSchemaManager();

        $table1 = new Table('child');
        $table1->addColumn('id', 'integer', ['autoincrement' => true]);
        $table1->addColumn('parent_id', 'integer');
        $table1->setPrimaryKey(['id']);
        $table1->addForeignKeyConstraint('parent', ['parent_id'], ['id']);

        $table2 = new Table('parent');
        $table2->addColumn('id', 'integer', ['autoincrement' => true]);
        $table2->setPrimaryKey(['id']);

        $diff = new SchemaDiff([$table1, $table2]);
        $sqls = $diff->toSql($this->connection->getDatabasePlatform());

        $this->assertEquals([
            'CREATE TABLE parent (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)',
            'CREATE TABLE child (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, parent_id INTEGER NOT NULL, CONSTRAINT FK_22B35429727ACA70 FOREIGN KEY (parent_id) REFERENCES parent (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_22B35429727ACA70 ON child (parent_id)',
        ], $sqls);

        foreach ($sqls as $sql) {
            $this->connection->exec($sql);
        }

        $schema = $schemaManager->createSchema();

        $this->assertCount(1, $schema->getTable('child')->getForeignKeys());

        $offlineSchema = new Schema([$table1, $table2]);

        $diff = Comparator::compareSchemas($offlineSchema, $schema);
        $sqls = $diff->toSql($this->connection->getDatabasePlatform());

        $this->assertEquals([], $sqls);
    }
}
