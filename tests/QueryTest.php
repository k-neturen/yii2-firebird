<?php

namespace neturen\unit\db\firebird;

/**
 * @group firebird
 */
class QueryTest extends \yiiunit\framework\db\QueryTest
{

    use FirebirdTestTrait;

    public $driverName = 'firebird';
}
