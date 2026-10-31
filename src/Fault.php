<?php
/** Domain errors with safe messages. @package MagaudevDonations */
namespace MagaudevDonations;

final class Fault extends \RuntimeException {
	public function __construct( public string $key, string $message, public int $status = 400 ) {
		parent::__construct( $message );
	}
}
