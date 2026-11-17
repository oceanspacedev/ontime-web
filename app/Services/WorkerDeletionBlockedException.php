<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when an employee, intern or freelancer with attendance, overtime, leave, correction or resignation
 * history is deleted: the foreign keys would cascade that payroll history away. The message says what to do
 * instead (end the contract or set the employee inactive).
 */
final class WorkerDeletionBlockedException extends RuntimeException {}
