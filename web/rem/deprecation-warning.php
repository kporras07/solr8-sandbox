<?php
// Deprecation Warning

class Foo implements \Serializable
{
    public function bar($why)
    {
        print "Foo::bar() - because $why<br>";
    }
}


$f = new Foo();
$f->bar("reasons<br>");
print "end<br>\n";