<?php

namespace neturen\unit\db\firebird;

use neturen\db\firebird\Schema;
use yii\db\Expression;

/**
 * @group firebird
 */
class CommandTest extends \yiiunit\framework\db\CommandTest
{

    use FirebirdTestTrait;

    public $driverName = 'firebird';
    
    public function testAutoQuoting(): void
    {
        $db = $this->getConnection(false);

        $sql = 'SELECT [[id]], [[t.name]] FROM {{customer}} t';
        $command = $db->createCommand($sql);
        $this->assertEquals('SELECT id, t.name FROM customer t', $command->sql);
    }

    public function testColumnCase(): void
    {
        $this->markTestSkipped('Test for travis with exit code 139');
        return;
        
        $db = $this->getConnection(false);
        
        //Force to use LOWER CASE
        $this->assertEquals(\PDO::CASE_LOWER, $db->slavePdo->getAttribute(\PDO::ATTR_CASE));

        $sql = 'SELECT [[customer_id]], [[total]] FROM {{order}}';
        $rows = $db->createCommand($sql)->queryAll();
        $this->assertTrue(isset($rows[0]));
        $this->assertTrue(isset($rows[0]['customer_id']));
        $this->assertTrue(isset($rows[0]['total']));

        $db->slavePdo->setAttribute(\PDO::ATTR_CASE, \PDO::CASE_LOWER);
        $rows = $db->createCommand($sql)->queryAll();
        $this->assertTrue(isset($rows[0]));
        $this->assertTrue(isset($rows[0]['customer_id']));
        $this->assertTrue(isset($rows[0]['total']));

        $db->slavePdo->setAttribute(\PDO::ATTR_CASE, \PDO::CASE_UPPER);
        $rows = $db->createCommand($sql)->queryAll();
        $this->assertTrue(isset($rows[0]));
        $this->assertTrue(isset($rows[0]['CUSTOMER_ID']));
        $this->assertTrue(isset($rows[0]['TOTAL']));
    }
    
    public function testBindParamValue(): void
    {
        if (!$this->getConnection(false)->supportBlobDataType) {
            $this->markTestSkipped('BLOB bug for PHP <= 7.0.13, see https://bugs.php.net/bug.php?id=61183');
        }
        
        $db = $this->getConnection();

        // bindParam
        $sql = 'INSERT INTO {{customer}}([[email]], [[name]], [[address]]) VALUES (:email, :name, :address)';
        $command = $db->createCommand($sql);
        $email = 'user4@example.com';
        $name = 'user4';
        $address = 'address4';
        $command->bindParam(':email', $email);
        $command->bindParam(':name', $name);
        $command->bindParam(':address', $address);
        $command->execute();

        $sql = 'SELECT [[name]] FROM {{customer}} WHERE [[email]] = :email';
        $command = $db->createCommand($sql);
        $command->bindParam(':email', $email);
        $this->assertEquals($name, $command->queryScalar());

        $sql = <<<SQL
INSERT INTO {{type}} ([[int_col]], [[char_col]], [[float_col]], [[blob_col]], [[numeric_col]], [[bool_col]])
  VALUES (:int_col, :char_col, :float_col, :blob_col, :numeric_col, :bool_col)
SQL;
        $command = $db->createCommand($sql);
        $intCol = 123;
        $charCol = str_repeat('abc', 33) . 'x'; // a 100 char string
        $boolCol = false;
        $command->bindParam(':int_col', $intCol, \PDO::PARAM_INT);
        $command->bindParam(':char_col', $charCol);
        $command->bindParam(':bool_col', $boolCol, \PDO::PARAM_BOOL);
        
        $floatCol = 1.23;
        $numericCol = '1.23';
        $blobCol = "\x10\x11\x12";
        $command->bindParam(':float_col', $floatCol);
        $command->bindParam(':numeric_col', $numericCol);
        $command->bindParam(':blob_col', $blobCol);
        
        $this->assertEquals(1, $command->execute());

        $command = $db->createCommand('SELECT [[int_col]], [[char_col]], [[float_col]], [[blob_col]], [[numeric_col]], [[bool_col]] FROM {{type}}');
        
        $row = $command->queryOne();
        $this->assertEquals($intCol, $row['int_col']);
        $this->assertEquals($charCol, $row['char_col']);
        $this->assertEquals($floatCol, $row['float_col']);

        $this->assertEquals($blobCol, $row['blob_col']);
        $this->assertEquals($numericCol, $row['numeric_col']);
        $this->assertEquals($boolCol, (int) $row['bool_col']);

        // bindValue
        $sql = 'INSERT INTO {{customer}}([[email]], [[name]], [[address]]) VALUES (:email, \'user5\', \'address5\')';
        $command = $db->createCommand($sql);
        $command->bindValue(':email', 'user5@example.com');
        $command->execute();

        $sql = 'SELECT [[email]] FROM {{customer}} WHERE [[name]] = :name';
        $command = $db->createCommand($sql);
        $command->bindValue(':name', 'user5');
        $this->assertEquals('user5@example.com', $command->queryScalar());
    }

    /**
     * Test whether param binding works in other places than WHERE
     * @dataProvider paramsNonWhereProvider
     */
    public function testBindParamsNonWhere($sql): void
    {
        $this->markTestSkipped('firebird does not support parameter in function');
    }

    public function testBatchInsert(): void
    {
        $command = $this->getConnection()->createCommand();
        $command->batchInsert(
            '{{customer}}',
            ['email', 'name', 'address'],
            [
                ['t1@example.com', 't1', 't1 address'],
                ['t2@example.com', null, false],
            ]
        );
        $this->assertEquals(2, $command->execute());

        // @see https://github.com/yiisoft/yii2/issues/11693
        $command = $this->getConnection(false)->createCommand();
        $command->batchInsert(
            '{{customer}}',
            ['email', 'name', 'address'],
            []
        );
        $this->assertEquals(0, $command->execute());
    }

    public function testInsertExpression(): void
    {
        $db = $this->getConnection();
        $db->createCommand('DELETE FROM {{order_with_null_fk}}')->execute();

        $expression = "EXTRACT(YEAR FROM TIMESTAMP 'now')";

        $command = $db->createCommand();
        $command->insert(
            '{{order_with_null_fk}}',
            [
                'created_at' => new Expression($expression),
                'total' => 1,
            ]
        )->execute();
        $this->assertEquals(1, $db->createCommand('SELECT COUNT(*) FROM {{order_with_null_fk}}')->queryScalar());
        $record = $db->createCommand('SELECT [[created_at]] FROM {{order_with_null_fk}}')->queryOne();
        $this->assertEquals([
            'created_at' => date('Y'),
        ], $record);
    }

    public function testCreateTable(): void
    {
        $db = $this->getConnection();

        if ($db->getSchema()->getTableSchema('testCreateTable') !== null) {
            $db->createCommand()->dropTable('testCreateTable')->execute();
            //Update metadata in connection
            $db->close();
            $db->open();
        }

        $db->createCommand()->createTable('testCreateTable', ['id' => Schema::TYPE_PK, 'bar' => Schema::TYPE_INTEGER])->execute();
        //Update metadata in connection
        $db->close();
        $db->open();

        $db->createCommand()->insert('testCreateTable', ['bar' => 1])->execute();
        $records = $db->createCommand('SELECT [[id]], [[bar]] FROM {{testCreateTable}};')->queryAll();
        $this->assertEquals([
            ['id' => 1, 'bar' => 1],
        ], $records);
    }

    public function testAlterTable(): void
    {
        $db = $this->getConnection();

        if ($db->getSchema()->getTableSchema('testAlterTable') !== null) {
            $db->createCommand()->dropTable('testAlterTable')->execute();
            //Update metadata in connection
            $db->close();
            $db->open();
        }

        $db->createCommand()->createTable('testAlterTable', ['id' => Schema::TYPE_PK, 'bar' => Schema::TYPE_INTEGER])->execute();
        //Update metadata in connection
        $db->close();
        $db->open();

        $db->createCommand()->insert('testAlterTable', ['bar' => 1])->execute();

        $db->createCommand()->alterColumn('testAlterTable', 'bar', Schema::TYPE_STRING)->execute();
        //Update metadata in connection
        $db->close();
        $db->open();

        $db->createCommand()->insert('testAlterTable', ['bar' => 'hello'])->execute();
        $records = $db->createCommand('SELECT [[id]], [[bar]] FROM {{testAlterTable}};')->queryAll();
        $this->assertEquals([
            ['id' => 1, 'bar' => 1],
            ['id' => 2, 'bar' => 'hello'],
        ], $records);
    }

    public function testRenameTable(): void
    {
        $this->expectException('\yii\base\NotSupportedException');
        parent::testRenameTable();
    }

    public function testLastInsertId(): void
    {
        $db = $this->getConnection();

        $sql = 'INSERT INTO {{profile}}([[description]]) VALUES (\'non duplicate\')';
        $command = $db->createCommand($sql);
        $command->execute();
        $this->assertEquals(3, $db->getSchema()->getLastInsertID('gen_profile_id'));
    }
    
    public function testInsertSelect(): void
    {
        $db = $this->getConnection(false);
        
        /**
         * @see https://firebirdsql.org/file/documentation/reference_manuals/fblangref25-en/html/fblangref25-dml-insert.html#fblangref25-dml-insert-select-unstable
         */
        if (!$db->supportStableCursor) {
            $this->expectException('\yii\base\NotSupportedException');
            $this->expectExceptionMessage('Firebird < 3.0.0 has the "Unstable Cursor" problem');
        }
        parent::testInsertSelect();
    }
    
    public function testInsertSelectAlias(): void
    {
        $db = $this->getConnection(false);
        
        /**
         * @see https://firebirdsql.org/file/documentation/reference_manuals/fblangref25-en/html/fblangref25-dml-insert.html#fblangref25-dml-insert-select-unstable
         */
        if (!$db->supportStableCursor) {
            $this->expectException('\yii\base\NotSupportedException');
            $this->expectExceptionMessage('Firebird < 3.0.0 has the "Unstable Cursor" problem');
        }
        parent::testInsertSelectAlias();
    }
    
    /**
     * Test INSERT INTO ... SELECT SQL statement with wrong query object
     *
     * @dataProvider invalidSelectColumns
     */
    public function testInsertSelectFailed($invalidSelectColumns): void
    {
        $db = $this->getConnection(false);
        
        /**
         * @see https://firebirdsql.org/file/documentation/reference_manuals/fblangref25-en/html/fblangref25-dml-insert.html#fblangref25-dml-insert-select-unstable
         */
        if (!$db->supportStableCursor) {
            $this->expectException('\yii\base\NotSupportedException');
            $this->expectExceptionMessage('Firebird < 3.0.0 has the "Unstable Cursor" problem');

            $query = (new \yii\db\Query())->select($invalidSelectColumns)->from('{{customer}}');
            $db->createCommand()->insert('{{customer}}', $query)->execute();
            return;
        }
        parent::testInsertSelectFailed($invalidSelectColumns);
    }

    public function testNoTablenameReplacement(): void
    {
        $db = $this->getConnection();

        $db->createCommand()->insert(
            '{{customer}}',
            [
                'name' => 'Some {{weird}} name',
                'email' => 'test@example.com',
                'address' => 'Some {{%weird}} address',
            ]
        )->execute();
        // Firebird requires the sequence name to get the last insert ID after Command::insert()
        $customerId = $db->getLastInsertID('gen_customer_id');
        $customer = $db->createCommand('SELECT * FROM {{customer}} WHERE id=' . $customerId)->queryOne();
        $this->assertEquals('Some {{weird}} name', $customer['name']);
        $this->assertEquals('Some {{%weird}} address', $customer['address']);

        $db->createCommand()->update(
            '{{customer}}',
            [
                'name' => 'Some {{updated}} name',
                'address' => 'Some {{%updated}} address',
            ],
            ['id' => $customerId]
        )->execute();
        $customer = $db->createCommand('SELECT * FROM {{customer}} WHERE id=' . $customerId)->queryOne();
        $this->assertEquals('Some {{updated}} name', $customer['name']);
        $this->assertEquals('Some {{%updated}} address', $customer['address']);
    }

    public function testsInsertQueryAsColumnValue(): void
    {
        $time = time();

        $db = $this->getConnection();
        $db->createCommand('DELETE FROM {{order_with_null_fk}}')->execute();

        $command = $db->createCommand();
        $command->insert('{{order}}', [
            'customer_id' => 1,
            'created_at' => $time,
            'total' => 42,
        ])->execute();
        // Firebird requires the sequence name to get the last insert ID after Command::insert()
        $orderId = $db->getLastInsertID('gen_order_id');

        $columnValueQuery = new \yii\db\Query();
        $columnValueQuery->select('created_at')->from('{{order}}')->where(['id' => $orderId]);

        $command = $db->createCommand();
        $command->insert(
            '{{order_with_null_fk}}',
            [
                'customer_id' => $orderId,
                'created_at' => $columnValueQuery,
                'total' => 42,
            ]
        )->execute();

        $this->assertEquals($time, $db->createCommand('SELECT [[created_at]] FROM {{order_with_null_fk}} WHERE [[customer_id]] = ' . $orderId)->queryScalar());

        $db->createCommand('DELETE FROM {{order_with_null_fk}}')->execute();
        $db->createCommand('DELETE FROM {{order}} WHERE [[id]] = ' . $orderId)->execute();
    }

    public function testBatchInsertDataTypesLocale(): void
    {
        $locale = setlocale(LC_NUMERIC, 0);
        if (false === $locale) {
            $this->markTestSkipped('Your platform does not support locales.');
        }
        $db = $this->getConnection();

        try {
            // This one sets decimal mark to comma sign
            setlocale(LC_NUMERIC, 'ru_RU.utf8');

            $cols = ['int_col', 'char_col', 'float_col', 'bool_col'];
            $data = [
                [1, 'A', 9.735, true],
                [2, 'B', -2.123, false],
                [3, 'C', 2.123, false],
            ];

            $db->createCommand()->delete('type')->execute();
            $db->createCommand()->batchInsert('type', $cols, $data)->execute();

            $data = $db->createCommand('SELECT int_col, char_col, float_col, bool_col FROM {{type}} WHERE [[int_col]] IN (1,2,3) ORDER BY [[int_col]];')->queryAll();
            $this->assertEquals(3, \count($data));
            $this->assertEquals(1, $data[0]['int_col']);
            $this->assertEquals(2, $data[1]['int_col']);
            $this->assertEquals(3, $data[2]['int_col']);
            $this->assertEquals('A', rtrim($data[0]['char_col']));
            $this->assertEquals('B', rtrim($data[1]['char_col']));
            $this->assertEquals('C', rtrim($data[2]['char_col']));
            // pdo_firebird returns DOUBLE PRECISION as a string with 16 significant digits (e.g. "9.734999999999999")
            $this->assertEqualsWithDelta(9.735, (float) $data[0]['float_col'], 1e-12);
            $this->assertEqualsWithDelta(-2.123, (float) $data[1]['float_col'], 1e-12);
            $this->assertEqualsWithDelta(2.123, (float) $data[2]['float_col'], 1e-12);
            $this->assertEquals('1', $data[0]['bool_col']);
            $this->assertIsOneOf($data[1]['bool_col'], ['0', false]);
            $this->assertIsOneOf($data[2]['bool_col'], ['0', false]);
        } finally {
            setlocale(LC_NUMERIC, $locale);
        }
    }
    
    public function testAutoRefreshTableSchema(): void
    {
        $db = $this->getConnection(false);

        if ($db->getSchema()->getTableSchema('test') !== null) {
            $db->createCommand()->dropTable('test')->execute();
        }

        parent::testAutoRefreshTableSchema();
    }
    
    public static function batchInsertSqlProvider(): array
    {
        $data = parent::batchInsertSqlProvider();
        
        $data['issue11242']['expected'] = "EXECUTE block AS BEGIN INSERT INTO type (int_col, float_col, char_col) VALUES (NULL, NULL, 'Kyiv {{city}}, Ukraine'); END;";
        $data['wrongBehavior']['expected'] = "EXECUTE block AS BEGIN INSERT INTO type (type.int_col, float_col, char_col) VALUES ('', NULL, 'Kyiv {{city}}, Ukraine'); END;";
        
        // Bingind on block not work
        unset($data['batchInsert binds params from expression']);
        
        return $data;
    }

    public function testCreateView(): void
    {
        $db = $this->getConnection(false);
        if ($db->getSchema()->getTableSchema('testCreateView') !== null) {
            $db->createCommand()->dropView('testCreateView')->execute();
        }
        parent::testCreateView();
    }

    /**
     * @dataProvider upsertProvider
     * @param array $firstData
     * @param array $secondData
     */
    public function testUpsert(array $firstData, array $secondData): void
    {
        $db = $this->getConnection();
        if ($firstData['params'][1] instanceof \yii\db\Query && !$db->supportStableCursor) {
            $this->expectException('\yii\base\NotSupportedException');
            $this->expectExceptionMessage('Firebird < 3.0.0 has the "Unstable Cursor" problem');
        }
        
        if (!$this->getConnection(false)->supportBlobDataType) {
            //Change BLOB to VARCHAR(8191)
            $db->createCommand()->renameColumn('{{T_upsert}}', 'address', 'address_old')->execute();
            $db->createCommand()->addColumn('{{T_upsert}}', 'address', 'string(8191)')->execute();
            $db->createCommand()->update('{{T_upsert}}', ['address'=>new Expression('CAST(address_old AS VARCHAR(8191))')])->execute();
            $db->createCommand()->dropColumn('{{T_upsert}}', 'address_old')->execute();
            $db->close();
            $db->open();
        }
        
        $this->assertEquals(0, $db->createCommand('SELECT COUNT(*) FROM {{T_upsert}}')->queryScalar());
        $this->performAndCompareUpsertResult($db, $firstData);
        $this->assertEquals(1, $db->createCommand('SELECT COUNT(*) FROM {{T_upsert}}')->queryScalar());
        $this->performAndCompareUpsertResult($db, $secondData);
    }

}
