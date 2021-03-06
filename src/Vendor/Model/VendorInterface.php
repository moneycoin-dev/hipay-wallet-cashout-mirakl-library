<?php

namespace HiPay\Wallet\Mirakl\Vendor\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represent an entity that is able to receive money from HiPay
 * Uses Symfony Validation assertion to ensure basic data integrity.
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
interface VendorInterface
{
    /**
     * @return int|null if operator
     *
     * @Assert\NotBlank(groups={"Default"})
     * @Assert\Type(type="integer", groups={"Default"})
     * @Assert\GreaterThan(value = 0, groups={"Default"})
     * @Assert\IsNull(groups={"Operator"})
     */
    public function getMiraklId();

    /**
     * @param int $id|null if operator
     *
     * @return void
     */
    public function setMiraklId($id);

    /**
     * @return string
     *
     * @Assert\NotBlank()
     * @Assert\Type(type="string")
     * @Assert\Email()
     */
    public function getEmail();

    /**
     * @param string $email
     *
     * @return void
     */
    public function setEmail($email);

    /**
     * @return int
     */
    public function getHiPayId();

    /**
     * @param int $id
     *
     * @return void
     */
    public function setHiPayId($id);

    /**
     * @return int
     */
    public function getHiPayUserSpaceId();

    /**
     * @param int $id
     *
     * @return void
     */
    public function setHiPayUserSpaceId($id);

    /**
     * @return int
     */
    public function getHiPayIdentified();

    /**
     * @param int $id
     *
     * @return void
     */
    public function setHiPayIdentified($id);
}
