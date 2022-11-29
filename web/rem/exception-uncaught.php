<?php
// Exception uncaught

class Thrower {
  public function throw() {
    throw new Exception();
  }
}

$thrower = new Thrower();
$thrower->throw();