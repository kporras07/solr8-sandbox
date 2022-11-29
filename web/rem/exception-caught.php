<?php
// Exception caught

class Thrower {
  public function throw() {
    throw new Exception();
  }
}

try {
  $thrower = new Thrower();
  $thrower->throw();
} catch (\Exception $e) {
  echo "Caught exception";
}