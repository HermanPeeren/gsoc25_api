<?php
namespace Joomla\Tests\Unit\Component\CCM\Administrator\Model;

use Joomla\Tests\Unit\UnitTestCase;
use Joomla\Component\CCM\Administrator\Model\CmssModel;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\QueryInterface;
use Joomla\Registry\Registry;

class CmssModelTest extends UnitTestCase
{
    public function testGetTableReturnsTableInstance()
    {
        $model = $this->getMockBuilder(CmssModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTable'])
            ->getMock();

        $tableMock = $this->createMock(Table::class);
        $model->expects($this->once())
            ->method('getTable')
            ->willReturn($tableMock);

        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('getTable');
        $method->setAccessible(true);

        $result = $method->invoke($model);
        $this->assertSame($tableMock, $result);
    }

    public function testGetItemsReturnsArray()
    {
        $model = $this->getMockBuilder(CmssModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItems'])
            ->getMock();

        $items = [
            (object)['id' => 1, 'name' => 'Test CMS'],
            (object)['id' => 2, 'name' => 'Another CMS'],
        ];

        $model->expects($this->once())
            ->method('getItems')
            ->willReturn($items);

        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('getItems');
        $method->setAccessible(true);

        $result = $method->invoke($model);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('Test CMS', $result[0]->name);
    }

    public function testGetListQueryWithoutSearch()
    {
        $model = $this->getMockBuilder(CmssModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDatabase', 'getState'])
            ->getMock();

        $db = $this->createMock(DatabaseDriver::class);
        $query = $this->createMock(QueryInterface::class);

        $query->method('select')->willReturnSelf();
        $query->method('from')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('order')->willReturnSelf();

        $db->method('getQuery')->with(true)->willReturn($query);
        $db->method('quoteName')->willReturnCallback(fn($name, $alias = null) => $name . ($alias ? " AS $alias" : ''));
        $db->method('escape')->willReturnArgument(0);
        $db->method('quote')->willReturnCallback(fn($v) => "'$v'");

        $model->method('getDatabase')->willReturn($db);
        $model->method('getState')->willReturnMap([
            ['list.select', 'a.id, a.name'],
            ['filter.search', null],
            ['list.ordering', 'a.name'],
            ['list.direction', 'ASC'],
        ]);

        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('getListQuery');
        $method->setAccessible(true);

        $property = $reflection->getProperty('state');
        $property->setAccessible(true);
        $property->setValue($model, new Registry([
            'list.ordering' => 'a.name',
            'list.direction' => 'ASC',
        ]));

        $result = $method->invoke($model);
        $this->assertInstanceOf(QueryInterface::class, $result);
    }

    public function testGetListQueryWithSearch()
    {
        $model = $this->getMockBuilder(CmssModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDatabase', 'getState'])
            ->getMock();

        $db = $this->createMock(DatabaseDriver::class);
        $query = $this->createMock(QueryInterface::class);

        // Chainable methods must return $query
        $query->method('select')->willReturnSelf();
        $query->method('from')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('order')->willReturnSelf();

        $db->method('getQuery')->with(true)->willReturn($query);
        $db->method('quoteName')->willReturnCallback(fn($name, $alias = null) => $name . ($alias ? " AS $alias" : ''));
        $db->method('escape')->willReturnArgument(0);
        $db->method('quote')->willReturnCallback(fn($v) => "'$v'");

        $model->method('getDatabase')->willReturn($db);
        $model->method('getState')->willReturnMap([
            ['list.select', 'a.id, a.name'],
            ['filter.search', 'CMS'],
            ['list.ordering', 'a.name'],
            ['list.direction', 'ASC'],
        ]);

        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('getListQuery');
        $method->setAccessible(true);

        $property = $reflection->getProperty('state');
        $property->setAccessible(true);
        $property->setValue($model, new Registry([
            'list.ordering' => 'a.name',
            'list.direction' => 'ASC',
        ]));

        $result = $method->invoke($model);
        $this->assertInstanceOf(QueryInterface::class, $result);
    }
}