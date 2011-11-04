<?php
/**
 * This file is part of the Serquant library.
 *
 * PHP version 5.3
 *
 * @category Serquant
 * @package  Exception
 * @author   Guillaume Oriol <goriol@serquant.com>
 * @license  http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @link     http://www.serquant.com/
 */
namespace Serquant\DependencyInjection\Exception;

use Serquant\DependencyInjection\Exception;

/**
 * Exception thrown if an argument does not match with the expected value.
 *
 * @category Serquant
 * @package  Exception
 * @author   Guillaume Oriol <goriol@serquant.com>
 * @license  http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @link     http://www.serquant.com/
 */
class InvalidArgumentException
    extends \InvalidArgumentException
    implements Exception
{
}