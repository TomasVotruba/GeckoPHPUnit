<?php

declare(strict_types=1);

/*
 * This file is part of the GeckoPackages.
 *
 * (c) GeckoPackages https://github.com/GeckoPackages
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * @internal
 *
 * @author SpacePossum
 */
final class ReadMeGenerator
{
    /**
     * @param string[] $classes
     *
     * @return string
     */
    public function generateReadMe(array $classes): string
    {
        $docs = [];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $classDoc = $this->getClassDoc($reflection->getDocComment());
            if (false === $classDoc) {
                throw new \UnexpectedValueException(sprintf('Missing class doc for "%s".', $class));
            }

            if (array_key_exists('internal', $classDoc['tags'])) {
                continue;
            }

            $docs[$class] = ['classDoc' => $classDoc, 'methods' => []];
            $reflectionMethods = $reflection->getMethods();

            /** @var ReflectionMethod $method */
            foreach ($reflectionMethods as $method) {
                $methodName = $method->name;
                $method = $reflection->getMethod($methodName);
                if (!$method->isPublic()) {
                    continue;
                }

                try {
                    $doc = $this->getMethodDoc($method->getDocComment());
                } catch (\UnexpectedValueException $e) {
                    throw new \UnexpectedValueException(sprintf('Exception when parsing "%s", "%s".', $class, $methodName), 1, $e);
                }

                if (false === $doc) {
                    throw new \UnexpectedValueException(sprintf('Missing doc for "%s:%s".', $class, $methodName));
                }

                if ('' === $doc['doc']) {
                    throw new \UnexpectedValueException(sprintf('Empty description for doc "%s:%s".', $class, $methodName));
                }

                $params = $method->getParameters();
                if (count($params) !== count($doc['params'])) {
                    throw new \UnexpectedValueException(sprintf('Parameters description in doc of method "%s:%s" mismatched.', $class, $methodName));
                }

                foreach ($params as $param) {
                    if (!array_key_exists($param->getName(), $doc['params'])) {
                        throw new \UnexpectedValueException(sprintf('Missing parameter description in doc of method "%s:%s" for "%s".', $class, $methodName, $param->getName()));
                    }

                    if ($param->isDefaultValueAvailable()) {
                        $doc['params'][$param->getName()]['default'] = $param->getDefaultValue();
                    }
                }
                $docs[$class]['methods'][$methodName] = ['doc' => $doc, 'name' => $methodName];
            }

            ksort($docs[$class]['methods']);

            // Merge negatives
            foreach ($docs[$class]['methods'] as $methodName => $values) {
                if (0 === strpos($methodName, 'assert') && false !== strpos($methodName, 'Not')) {
                    $positive = str_replace('Not', '', $methodName);
                    if (!array_key_exists($positive, $docs[$class]['methods'])) {
                        continue;
                    }

                    $docs[$class]['methods'][$positive]['not'] = $docs[$class]['methods'][$methodName];
                    unset($docs[$class]['methods'][$methodName]);
                }
            }
        }

        ksort($docs);

        $doc = '';
        $listing = '';
        foreach ($docs as $class => $properties) {
            $shortClass = substr($class, strrpos($class, '\\') + 1);
            $classDoc = $properties['classDoc'];

            $listing .= sprintf("\n- **%s**<br/>\n  %s", $shortClass, $classDoc['summary']);
            $doc .= sprintf("\n## %s\n###### %s\n%s\n", $shortClass, $class, $classDoc['doc']);
            if ('' !== $classDoc['doc']) {
                $doc .= "\n";
            }

            if (array_key_exists('requires', $classDoc['tags'])) {
                if (count($classDoc['tags']['requires']) === 1) {
                    $doc .= sprintf("Requires %s.\n", $classDoc['tags']['requires'][0]);
                } else {
                    $doc .= "Requires:\n";
                    foreach ($classDoc['tags']['requires'] as $reqLine) {
                        if ('' !== $reqLine) {
                            $doc .= '* '.$reqLine."\n";
                        }
                    }
                }
            }

            $doc .= "\n### Methods\n";
            foreach ($properties['methods'] as $method => $methodDescription) {
                $doc .= $this->generateMethodDescription($method, $methodDescription['doc']);
                if (array_key_exists('not', $methodDescription)) {
                    $doc .= sprintf("\nThe inverse assertion%s\n", $this->generateMethodDescription($methodDescription['not']['name'], $methodDescription['not']['doc']));
                }
            }
        }

        $readMeTemplate = <<<EOF
#### GeckoPackages

# PHPUnit extensions

Provides additional asserts to be used in [PHPUnit](https://phpunit.de/) tests.
The asserts are provided using Traits so no changes are needed in the hierarchy of test classes.

The additional asserts are provided through the Traits:
#GENERATED_LISTING#

See Traits and asserts listing for more details.

### Requirements

PHP 7 / PHPUnit 6

<sub>Use ^v2.0 if you are using PHPUnit 5.</sub>

### Install

The package can be installed using [Composer](https://getcomposer.org/).
Add the package to your `composer.json`.

```
"require-dev": {
    "gecko-packages/gecko-php-unit" : "^3.0"
}
```

<sub>Please note we hint `-dev` here because typically you only need tests during development.</sub>

### Usage

Example usage of `FileSystemAssertTrait`.

```php
class myTest extends \PHPUnit_Framework_TestCase
{
    use \GeckoPackages\PHPUnit\Asserts\FileSystemAssertTrait;

    public function testFilePermissionsOfThisFile()
    {
        \$this->assertFileHasPermissions('lrwxrwxrwx', __FILE__);
    }
}

```

# Traits and asserts listing
#GENERATED_BODY#
### License

The project is released under the MIT license, see the LICENSE file.

### Contributions

Contributions are welcome!

### Semantic Versioning

This project follows [Semantic Versioning](http://semver.org/).

<sub>Kindly note:
We do not keep a backwards compatible promise on code annotated with `@internal`, the tests and tooling (such as document generation) of the project itself
nor the content and/or format of exception/error messages.</sub>

EOF;
        $readMeTemplate = str_replace('#GENERATED_LISTING#', $listing, $readMeTemplate);

        return str_replace('#GENERATED_BODY#', $doc, $readMeTemplate);
    }

    private function generateMethodDescription(string $method, array $methodDescription): string
    {
        $params = '';
        foreach ($methodDescription['params'] as $name => $values) {
            if (array_key_exists('default', $values)) {
                $params .= sprintf(' [,%s $%s = %s]', $values['type'], $name, is_string($values['default']) ? '\''.$values['default'].'\'' : $values['default']);
            } else {
                $params .= sprintf(', %s $%s', $values['type'], $name);
            }
        }

        return sprintf("\n#### %s()\n###### %s(%s)\n%s\n", $method, $method, substr($params, 2), $methodDescription['doc']);
    }

    private function getClassDoc(string $docString)
    {
        preg_match_all("/[^\n\r]+[\r\n]*/", $docString, $matches);
        if (count($matches[0]) < 1) {
            return false;
        }

        $doc = ['summary' => '', 'doc' => '', 'tags' => []];

        $capture = 'summary';
        foreach ($matches[0] as $docLine) {
            $docLine = trim($docLine);
            if ('/**' === $docLine) { // start of doc
                continue;
            }

            if ('*/' === $docLine) { // end of doc
                break;
            }

            if ('*' === $docLine) { // empty line
                $capture = 'doc';
            }

            if (0 === strpos($docLine, '* @')) {
                $docLine = trim(substr($docLine, 2));
                $tagDivision = strpos($docLine, ' ');
                if (false === $tagDivision) {
                    $index = substr($docLine, 1);
                    if (!array_key_exists($index, $doc['tags'])) {
                        $doc['tags'][$index] = [];
                    }

                    $doc['tags'][$index][] = '';
                } else {
                    $index = substr($docLine, 1, $tagDivision - 1);
                    if (!array_key_exists($index, $doc['tags'])) {
                        $doc['tags'][$index] = [];
                    }

                    $doc['tags'][$index][] = substr($docLine, $tagDivision + 1);
                }
            } elseif (strlen($docLine) > 2) {
                $doc[$capture] .= ltrim(substr($docLine, 2))."\n";
            }
        }

        $doc['summary'] = trim($doc['summary']);
        $doc['doc'] = trim($doc['doc']);

        return $doc;
    }

    /**
     * Parse a method PHPDoc into an array.
     *
     * @param string $doc
     *
     * @return array|false
     */
    private function getMethodDoc(string $doc)
    {
        preg_match_all("/[^\n\r]+[\r\n]*/", $doc, $matches);
        if (count($matches[0]) < 1) {
            return false;
        }

        $methodDoc = ['doc' => '', 'long' => '', 'params' => []];
        $capture = 'doc';
        foreach ($matches[0] as $docLine) {
            $docLine = trim($docLine);
            if ('/**' === $docLine || '*/' === $docLine) {
                continue;
            }

            if ('*' === $docLine) {
                $capture = 'long';

                continue;
            }

            if (0 === strpos($docLine, '* @param')) {
                $docLine = substr($docLine, 9);

                preg_match('#^(.+?\b)[\s]*\$(.+?\b)[\s]*(.+?\b)*#', $docLine, $matches);
                if (count($matches) < 3) {
                    throw new \UnexpectedValueException(sprintf("Matching failed for:\n------------------\n%s\n------------------", $doc));
                }

                $type = $matches[1];
                $name = $matches[2];
                //$description = count($matches[2]) > 2 ? $matches[3] : null;

                $methodDoc['params'][$name] = ['type' => $type];

                continue;
            }

            $methodDoc[$capture] .= substr($docLine, 2)."\n";
        }

        $methodDoc['doc'] = trim($methodDoc['doc']);
        $methodDoc['long'] = trim($methodDoc['long']);

        return $methodDoc;
    }
}
