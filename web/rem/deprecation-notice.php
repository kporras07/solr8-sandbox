<?php
// Deprecation Notice

class Foo implements ArrayAccess {
    public function offsetGet(mixed $offset) {}

    public function offsetSet(mixed $offset, mixed $value) {}
    public function offsetUnset(mixed $offset) {}
    public function offsetExists(mixed $offset) {}
}


$f = new Foo();
print "Done!";