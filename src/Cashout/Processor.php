<?php

namespace HiPay\Wallet\Mirakl\Cashout;

use DateTime;
use Exception;
use HiPay\Wallet\Mirakl\Api\Factory;
use HiPay\Wallet\Mirakl\Api\HiPay;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Soap\Transfer;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Status\BankInfo as BankInfoStatus;
use HiPay\Wallet\Mirakl\Cashout\Event\OperationEvent;
use HiPay\Wallet\Mirakl\Cashout\Model\Operation\ManagerInterface as OperationManager;
use HiPay\Wallet\Mirakl\Cashout\Model\Operation\OperationInterface;
use HiPay\Wallet\Mirakl\Cashout\Model\Operation\Status;
use HiPay\Wallet\Mirakl\Common\AbstractApiProcessor;
use HiPay\Wallet\Mirakl\Exception\UnconfirmedBankAccountException;
use HiPay\Wallet\Mirakl\Exception\UnidentifiedWalletException;
use HiPay\Wallet\Mirakl\Exception\WalletNotFoundException;
use HiPay\Wallet\Mirakl\Exception\WrongWalletBalance;
use HiPay\Wallet\Mirakl\Service\Validation\ModelValidator;
use HiPay\Wallet\Mirakl\Vendor\Model\VendorManagerInterface as VendorManager;
use HiPay\Wallet\Mirakl\Vendor\Model\VendorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Process the operations created by the cashout/initializer
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class Processor extends AbstractApiProcessor
{
    const SCALE = 2;
    /** @var  OperationManager */
    protected $operationManager;

    /** @var  VendorManager */
    protected $vendorManager;

    /** @var VendorInterface */
    protected $operator;

    /**
     * Processor constructor.
     *
     * @param EventDispatcherInterface $dispatcher
     * @param LoggerInterface $logger
     * @param Factory $factory
     * @param OperationManager $operationManager ,
     * @param VendorManager $vendorManager
     * @param VendorInterface $operator
     *
     * @throws \HiPay\Wallet\Mirakl\Exception\ValidationFailedException
     */
    public function __construct(
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
        Factory $factory,
        OperationManager $operationManager,
        VendorManager $vendorManager,
        VendorInterface $operator
    ) {
        parent::__construct($dispatcher, $logger, $factory);

        $this->operationManager = $operationManager;
        $this->vendorManager = $vendorManager;

        ModelValidator::validate($operator, 'Operator');
        $this->operator = $operator;
    }

    /**
     * Main processing function.
     *
     * @throws WrongWalletBalance
     * @throws WalletNotFoundException
     * @throws UnconfirmedBankAccountException
     * @throws UnidentifiedWalletException
     *
     * @codeCoverageIgnore
     */
    public function process()
    {
        $this->logger->info("Cashout Processor");

        //Transfer
        $this->transferOperations();

        //Withdraw
        $this->withdrawOperations();
    }

    /**
     * Execute the operation needing transfer.
     */
    protected function transferOperations()
    {
        $this->logger->info("Transfer operations");

        $toTransfer = $this->getTransferableOperations();

        $this->logger->info("Operation to transfer : " . count($toTransfer));

        /** @var OperationInterface $operation */
        foreach ($toTransfer as $operation) {
            try {
                $eventObject = new OperationEvent($operation);

                $this->dispatcher->dispatch('before.transfer', $eventObject);

                $transferId = $this->transfer($operation);

                $eventObject->setTransferId($transferId);
                $this->dispatcher->dispatch('after.transfer', $eventObject);

                $this->logger->info("[OK] Transfer operation ". $operation->getTransferId() ." executed");
            } catch (Exception $e) {
                $this->logger->info("[OK] Transfer operation failed");
                $this->handleException($e, 'critical');
            }
        }
    }
    /**
     * Execute the operation needing withdrawal.
     *
     */
    protected function withdrawOperations()
    {
        $this->logger->info("Withdraw operations");

        $toWithdraw = $this->getWithdrawableOperations();

        $this->logger->info("Operation to withdraw : " . count($toWithdraw));

        /** @var OperationInterface $operation */
        foreach ($toWithdraw as $operation) {
            try {
                //Create the operation event object
                $eventObject =  new OperationEvent($operation);

                //Dispatch the before.withdraw event
                $this->dispatcher->dispatch('before.withdraw', $eventObject);

                //Execute the withdrawal
                $withdrawId = $this->withdraw($operation);

                //Dispatch the after.withdraw
                $eventObject->setWithdrawId($withdrawId);
                $this->dispatcher->dispatch('after.withdraw', $eventObject);

                //Set operation new data
                $this->logger->info("[OK] Withdraw operation " . $operation->getWithdrawId(). " executed");
            } catch (Exception $e) {
                $this->logger->info("[OK] Withdraw operation failed");
                $this->handleException($e, 'critical');
            }
        }
    }

    /**
     * Transfer money between the technical
     * wallet and the operator|seller wallet.
     *
     * @param OperationInterface $operation
     *
     * @return int
     *
     * @throws Exception
     */
    public function transfer(OperationInterface $operation)
    {
        try {
            $vendor = $this->getVendor($operation);

            if (!$vendor || $this->hipay->isAvailable($vendor->getEmail())) {
                throw new WalletNotFoundException($vendor);
            }

            $operation->setHiPayId($vendor->getHiPayId());

            $transfer = new Transfer(
                round($operation->getAmount(), self::SCALE),
                $vendor,
                $this->operationManager->generatePublicLabel($operation),
                $this->operationManager->generatePrivateLabel($operation)
            );


            //Transfer
            $transferId = $this->hipay->transfer($transfer);

            $operation->setStatus(new Status(Status::TRANSFER_SUCCESS));
            $operation->setTransferId($transferId);
            $operation->setUpdatedAt(new DateTime());
            $this->operationManager->save($operation);

            return $transferId;
        } catch (Exception $e) {
            $operation->setStatus(new Status(Status::TRANSFER_FAILED));
            $operation->setUpdatedAt(new DateTime());
            $this->operationManager->save($operation);
            throw $e;
        }
    }

    /**
     * Put the money into the real bank account of the operator|seller.
     *
     * @param OperationInterface $operation
     * @return int
     * @throws Exception
     */
    public function withdraw(OperationInterface $operation)
    {
        try {
            $vendor = $this->getVendor($operation);

            if (!$vendor || $this->hipay->isAvailable($vendor->getEmail())) {
                throw new WalletNotFoundException($vendor);
            }

            if (!$this->hipay->isIdentified($vendor->getEmail())) {
                throw new UnidentifiedWalletException($vendor);
            }

            $bankInfoStatus = trim($this->hipay->bankInfosStatus($vendor));

            if ($bankInfoStatus != BankInfoStatus::VALIDATED) {
                throw new UnconfirmedBankAccountException(
                    new BankInfoStatus($bankInfoStatus),
                    $operation->getMiraklId()
                );
            }

            //Check account balance
            $amount = round(($operation->getAmount()), self::SCALE);
            $balance = round($this->hipay->getBalance($vendor), self::SCALE);
            if ($balance < $amount) {
                //Operator operation
                if (!$operation->getMiraklId()) {
                    $amount = $balance;
                    //Vendor operation
                } else {
                    throw new WrongWalletBalance(
                        $vendor->getMiraklId(),
                        $amount,
                        $balance
                    );
                }
            }

            $operation->setHiPayId($vendor->getHiPayId());

            //Withdraw
            $withdrawId = $this->hipay->withdraw(
                $vendor,
                $amount,
                $this->operationManager->generateWithdrawLabel($operation)
            );

            $operation->setWithdrawId($withdrawId);
            $operation->setStatus(new Status(Status::WITHDRAW_REQUESTED));
            $operation->setUpdatedAt(new DateTime());
            $operation->setWithdrawnAmount($amount);
            $this->operationManager->save($operation);

            return $withdrawId;
        } catch (Exception $e) {
            $operation->setStatus(new Status(Status::WITHDRAW_FAILED));
            $operation->setUpdatedAt(new DateTime());
            $this->operationManager->save($operation);
            throw $e;
        }
    }

    /**
     * Return the right vendor for an operation
     *
     * @param OperationInterface $operation
     *
     * @return VendorInterface|null
     */
    protected function getVendor(OperationInterface $operation)
    {
        if ($operation->getMiraklId()) {
            return $this->vendorManager->findByMiraklId($operation->getMiraklId());
        }
        return $this->operator;
    }

    /**
     * Fetch the operation to withdraw from the storage
     *
     * @return OperationInterface[]
     */
    protected function getWithdrawableOperations()
    {
        $previousDay = new DateTime('-1 day');

        $toWithdraw = $this->operationManager->findByStatus(
            new Status(Status::TRANSFER_SUCCESS)
        );
        $toWithdraw = array_merge(
            $toWithdraw,
            $this->operationManager
                ->findByStatusAndBeforeUpdatedAt(
                    new Status(Status::WITHDRAW_FAILED),
                    $previousDay
                )
        );
        return $toWithdraw;
    }

    /**
     * Fetch the operation to transfer from the storage
     * @return OperationInterface[]
     */
    protected function getTransferableOperations()
    {
        $previousDay = new DateTime('-1 day');
        //Transfer
        $toTransfer = $this->operationManager->findByStatus(
            new Status(Status::CREATED)
        );
        $toTransfer = array_merge(
            $toTransfer,
            $this->operationManager
                ->findByStatusAndBeforeUpdatedAt(
                    new Status(Status::TRANSFER_FAILED),
                    $previousDay
                )
        );
        return $toTransfer;
    }
}
