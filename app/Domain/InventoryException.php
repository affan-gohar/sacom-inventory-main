<?php

namespace App\Domain;

use DomainException;

/** A business rule refused the action. The message is safe to show to the admin. */
class InventoryException extends DomainException {}
