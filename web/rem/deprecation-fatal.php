<?php
// Deprecation Fatal

class Foo
{
    public function bar($why)
    {
        print "Foo::bar() - because $why<br>";
    }
}

class FooPrime extends Foo
{
    public function bar(string $why)
    {
        print "override $why<br>";
    }
}

$f = new FooPrime();
$f->bar("reasons<br>");
print "end<br>\n";