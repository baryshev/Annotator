# Annotator

## Requirements

* PHP 5.4
* Runkit extension

## Installation

    git clone git://github.com/zenovich/runkit.git
    cd runkit
    phpize && ./configure && make && sudo make install

**Note**: php developer tools (php5-dev for Ubuntu) need to be installed first.

After installation you should enable runkit extension by adding

    extension=runkit.so
    
in /etc/php5/conf.d or your php.ini.

## Usage

### Info

```php
<?php

require_once __DIR__ . '/Annotator.php';

class Advice {
    public static function infoStatic($point, $options) {
        var_dump($point);
        var_dump($options);
    }
}

class Test {
    /**
     * @info Advice::infoStatic hello world
     */
    public static function testInfoStatic() {
        return 'info';
    }
}

Annotator::compile('Test');
```

Result:

    array(2) {
      [0]=>
      string(4) "Test"
      [1]=>
      string(14) "testInfoStatic"
    }
    array(2) {
      [0]=>
      string(5) "hello"
      [1]=>
      string(5) "world"
    }

### Before

```php
<?php

require_once __DIR__ . '/Annotator.php';

class Advice {
    public function before($point, $params, $options) {
        $params['string'] = 'bar';
    }
}

class Test {
    /**
     * @registered_before
     */
    public function testBefore($string) {
        return $string;
    }
}

$advice = new Advice();

Annotator::register('registered_before', array($advice, 'before'), Annotator::BEFORE);

Annotator::compile('Test');

$test = new Test();

echo $test->testBefore('foo');
```

Result:

    bar

### After

```php
<?php

require_once __DIR__ . '/Annotator.php';

class Advice {
    public function power($point, $params, $options, $result) {
        return pow($result, $options[0]);
    }
}

class Test {
    /**
     * @power 4
     */
    public function testAfter($number) {
        return $number + 1;
    }
}

$advice = new Advice();

Annotator::register('power', array($advice, 'power'), Annotator::AFTER);

Annotator::compile('Test');

$test = new Test();

echo $test->testAfter(1);
```

Result:

    16

### Around

```php
<?php

require_once __DIR__ . '/Annotator.php';

class Advice {
    private static $cache = array();

    public function cache($point, $params, $options, $proceed) {
        if (array_key_exists($options[0], self::$cache)) {
            return self::$cache[$options[0]];
        } else {
            $result = $proceed();
            self::$cache[$options[0]] = $result;
            return $result;
        }
    }
}

class Test {
    /**
     * @cache around_cache_key
     */
    public function testAround($string) {
        echo 'testAround called' . PHP_EOL;
        return $string;
    }
}

$advice = new Advice();

Annotator::register('cache', array($advice, 'cache'), Annotator::AROUND);

Annotator::compile('Test');

$test = new Test();

echo $test->testAround('foo') . PHP_EOL;
echo $test->testAround('foo') . PHP_EOL;
echo $test->testAround('foo') . PHP_EOL;
```

Result:

    testAround called
    foo
    foo
    foo

### All together

```php
<?php

require_once __DIR__ . '/Annotator.php';

class Advice {
    public static function before1($point, $params, $options) {
        $params['string'] .= 'before1';
    }

    public static function before2($point, $params, $options) {
        $params['string'] .= ' before1';
    }

    public static function after1($point, $params, $options, $result) {
        return $result . ' after1';
    }

    public static function after2($point, $params, $options, $result) {
        return $result .= ' after2';
    }

    public static function around1($point, $params, $options, $proceed) {
        return $proceed() . ' around1';
    }

    public static function around2($point, $params, $options, $proceed) {
        return $proceed() . ' around2';
    }
}

class Test {
    /**
     * @before Advice::before1
     * @after Advice::after1
     * @around Advice::around1
     * @before Advice::before2
     * @after Advice::after2
     * @around Advice::around2
     */
    public function testMulti($string) {
        return $string;
    }
}

Annotator::compile('Test');

$test = new Test();

echo $test->testMulti('');
```

Result:

    before1 before1 around1 around2 after1 after2