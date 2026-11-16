<?php

namespace neturen\unit\db\firebird;

use yiiunit\data\ar\Customer;

/**
 * @group firebird
 */
class ActiveRecordTest extends \yiiunit\framework\db\ActiveRecordTest
{

    use FirebirdTestTrait;

    public $driverName = 'firebird';
    
    public function testAutoId()
    {
        $animal = new \yiiunit\data\ar\Animal();
        $animal->type = 'cat';

        $this->assertTrue($animal->save());

        $this->assertEquals(3, $animal->id);

        $customer = new Customer();
        $customer->email = 'test@test.net';
        $this->assertTrue($customer->save());
        
        $this->assertEquals(4, $customer->id);
    }
    
    
    public function testCustomColumns(): void
    {
        // find custom column
        $customer = Customer::find()->select(['{{customer}}.*', '([[status]]*2) AS [[status2]]'])
            ->where(['name' => 'user3'])->one();
        $this->assertEquals(3, $customer->id);
        $this->assertEquals(4, $customer->status2);
    }

    public function testPopulateWithoutPk(): void
    {
        $this->markTestSkipped();
    }
    
    public function testCastValues(): void
    {
        if (!$this->getConnection(false)->supportBlobDataType) {
            $this->markTestSkipped('BLOB bug for PHP <= 7.0.13, see https://bugs.php.net/bug.php?id=61183');
        }

        $model = new \yiiunit\data\ar\Type();
        $model->int_col = 123;
        $model->int_col2 = 456;
        $model->smallint_col = 42;
        $model->char_col = '1337';
        $model->char_col2 = 'test';
        $model->char_col3 = 'test123';
        $model->float_col = 3.742;
        $model->float_col2 = 42.1337;
        $model->bool_col = true;
        $model->bool_col2 = false;
        $model->save(false);

        /* @var $model \yiiunit\data\ar\Type */
        $model = \yiiunit\data\ar\Type::find()->one();
        $this->assertSame(123, $model->int_col);
        $this->assertSame(456, $model->int_col2);
        $this->assertSame(42, $model->smallint_col);
        $this->assertSame('1337', trim((string) $model->char_col));
        $this->assertSame('test', $model->char_col2);
        $this->assertSame('test123', $model->char_col3);
        $this->assertSame(3.742, $model->float_col);
        $this->assertSame(42.1337, $model->float_col2);
        // bool columns are SMALLINT in the fixture, Firebird < 3.0 has no BOOLEAN data type
        $this->assertSame(1, $model->bool_col);
        $this->assertSame(0, $model->bool_col2);
    }
}
