<?php

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Internal\CommitOrderCalculator;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use function array_merge;

/**
 * Schema Diff.
 */
class SchemaDiff
{
    /** @var Schema */
    public $fromSchema;

    /**
     * All added namespaces.
     *
     * @var string[]
     */
    public $newNamespaces = [];

    /**
     * All removed namespaces.
     *
     * @var string[]
     */
    public $removedNamespaces = [];

    /**
     * All added tables.
     *
     * @var Table[]
     */
    public $newTables = [];

    /**
     * All changed tables.
     *
     * @var TableDiff[]
     */
    public $changedTables = [];

    /**
     * All removed tables.
     *
     * @var Table[]
     */
    public $removedTables = [];

    /** @var Sequence[] */
    public $newSequences = [];

    /** @var Sequence[] */
    public $changedSequences = [];

    /** @var Sequence[] */
    public $removedSequences = [];

    /** @var ForeignKeyConstraint[] */
    public $orphanedForeignKeys = [];

    /**
     * Constructs an SchemaDiff object.
     *
     * @param Table[]     $newTables
     * @param TableDiff[] $changedTables
     * @param Table[]     $removedTables
     */
    public function __construct($newTables = [], $changedTables = [], $removedTables = [], ?Schema $fromSchema = null)
    {
        $this->newTables     = $newTables;
        $this->changedTables = $changedTables;
        $this->removedTables = $removedTables;
        $this->fromSchema    = $fromSchema;
    }

    /**
     * The to save sql mode ensures that the following things don't happen:
     *
     * 1. Tables are deleted
     * 2. Sequences are deleted
     * 3. Foreign Keys which reference tables that would otherwise be deleted.
     *
     * This way it is ensured that assets are deleted which might not be relevant to the metadata schema at all.
     *
     * @return string[]
     */
    public function toSaveSql(AbstractPlatform $platform)
    {
        return $this->_toSql($platform, true);
    }

    /**
     * @return string[]
     */
    public function toSql(AbstractPlatform $platform)
    {
        return $this->_toSql($platform, false);
    }

    /**
     * @param bool $saveMode
     *
     * @return string[]
     */
    protected function _toSql(AbstractPlatform $platform, $saveMode = false)
    {
        $sql = [];

        if ($platform->supportsSchemas()) {
            foreach ($this->newNamespaces as $newNamespace) {
                $sql[] = $platform->getCreateSchemaSQL($newNamespace);
            }
        }

        if ($platform->supportsForeignKeyConstraints() && $saveMode === false) {
            foreach ($this->orphanedForeignKeys as $orphanedForeignKey) {
                $sql[] = $platform->getDropForeignKeySQL($orphanedForeignKey, $orphanedForeignKey->getLocalTable());
            }
        }

        if ($platform->supportsSequences() === true) {
            foreach ($this->changedSequences as $sequence) {
                $sql[] = $platform->getAlterSequenceSQL($sequence);
            }

            if ($saveMode === false) {
                foreach ($this->removedSequences as $sequence) {
                    $sql[] = $platform->getDropSequenceSQL($sequence);
                }
            }

            foreach ($this->newSequences as $sequence) {
                $sql[] = $platform->getCreateSequenceSQL($sequence);
            }
        }

        $foreignKeySql = [];
        $createFlags   = AbstractPlatform::CREATE_INDEXES;

        if (! $platform->supportsCreateDropForeignKeyConstraints()) {
            $createFlags |= AbstractPlatform::CREATE_FOREIGNKEYS;
        }

        foreach ($this->getNewTablesSortedByDependencies() as $table) {
            $sql = array_merge($sql, $platform->getCreateTableSQL($table, $createFlags));

            if (! $platform->supportsCreateDropForeignKeyConstraints()) {
                continue;
            }

            foreach ($table->getForeignKeys() as $foreignKey) {
                $foreignKeySql[] = $platform->getCreateForeignKeySQL($foreignKey, $table);
            }
        }
        $sql = array_merge($sql, $foreignKeySql);

        if ($saveMode === false) {
            foreach ($this->removedTables as $table) {
                $sql[] = $platform->getDropTableSQL($table);
            }
        }

        foreach ($this->changedTables as $tableDiff) {
            $sql = array_merge($sql, $platform->getAlterTableSQL($tableDiff));
        }

        return $sql;
    }

    /**
     * Sorts tables by dependencies so that they are created in the right order.
     *
     * This is ncessary when one table depends on another while creating foreign key
     * constraints directly during CREATE TABLE.
     *
     * @return array<\Doctrine\DBAL\Schema\Table>
     */
    private function getNewTablesSortedByDependencies()
    {
        $commitOrderCalculator = new CommitOrderCalculator();
        $newTables             = [];

        foreach ($this->newTables as $table) {
            $newTables[$table->getName()] = true;
            $commitOrderCalculator->addNode($table->getName(), $table);
        }

        foreach ($this->newTables as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                $foreignTableName = $foreignKey->getForeignTableName();

                if (! isset($newTables[$foreignTableName])) {
                    continue;
                }

                $commitOrderCalculator->addDependency($foreignTableName, $table->getName(), 1);
            }
        }

        return $commitOrderCalculator->sort();
    }
}
