<?php
namespace Hipay\MiraklConnector\Cashout;

use DateTime;
use Exception;
use Hipay\MiraklConnector\Api\Hipay\Model\Soap\Transfer;
use Hipay\MiraklConnector\Cashout\Model\Operation\OperationInterface;
use Hipay\MiraklConnector\Cashout\Model\Operation\Status;
use Hipay\MiraklConnector\Common\AbstractProcessor;
use Hipay\MiraklConnector\Api\Mirakl;
use Hipay\MiraklConnector\Api\Mirakl\ConfigurationInterface
    as MiraklConfiguration;
use Hipay\MiraklConnector\Api\Hipay;
use Hipay\MiraklConnector\Api\Hipay\ConfigurationInterface
    as HipayConfiguration;
use Hipay\MiraklConnector\Exception\DispatchableException;
use Hipay\MiraklConnector\Exception\Event\ThrowException;
use Hipay\MiraklConnector\Exception\WrongWalletBalance;
use Hipay\MiraklConnector\Exception\NoWalletFoundException;
use Hipay\MiraklConnector\Exception\UnconfirmedBankAccountException;
use Hipay\MiraklConnector\Exception\UnidentifiedWalletException;
use Mustache_Engine;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Hipay\MiraklConnector\Cashout\Model\Operation\ManagerInterface
    as OperationManager;
use Hipay\MiraklConnector\Vendor\Model\ManagerInterface as VendorManager;
use Hipay\MiraklConnector\Api\Hipay\Model\Status\BankInfo as BankInfoStatus;

/**
 * File Processor.php
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class Processor extends AbstractProcessor
{
    /** @var  OperationManager */
    protected $operationManager;

    /** @var  VendorManager */
    protected $vendorManager;

    /**
     * Processor constructor.
     * @param MiraklConfiguration $miraklConfig
     * @param HipayConfiguration $hipayConfig
     * @param EventDispatcherInterface $dispatcher
     * @param LoggerInterface $logger
     * @param OperationManager $operationManager,
     * @param VendorManager $vendorManager
     */
    public function __construct(
        MiraklConfiguration $miraklConfig,
        HipayConfiguration $hipayConfig,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
        OperationManager $operationManager,
        VendorManager $vendorManager
    )
    {
        parent::__construct($miraklConfig, $hipayConfig, $dispatcher, $logger);
        $this->operationManager = $operationManager;
        $this->vendorManager = $vendorManager;
    }

    /**
     * Main processing function
     *
     * @param $publicLabelTemplate
     * @param $privateLabelTemplate
     * @param $withdrawLabelTemplate
     * @throws WrongWalletBalance
     * @throws NoWalletFoundException
     * @throws UnconfirmedBankAccountException
     * @throws UnidentifiedWalletException
     */
    public function process(
        $publicLabelTemplate,
        $privateLabelTemplate,
        $withdrawLabelTemplate
    )
    {
        $previousDay = new DateTime("-1 day");
        //Transfer
        $this->transferOperations(
            $previousDay,
            $publicLabelTemplate,
            $privateLabelTemplate
        );
        //Withdraw
        $this->withdrawOperations($previousDay, $withdrawLabelTemplate);


    }

    /**
     * @param $previousDay
     * @param $publicLabelTemplate
     * @param $privateLabelTemplate
     */
    public function transferOperations(
        $previousDay,
        $publicLabelTemplate,
        $privateLabelTemplate
    )
    {
        //Transfer
        $toTransfer = $this->operationManager->findByStatus(
            new Status(Status::CREATED)
        );
        $toTransfer = $toTransfer + $this->operationManager
                ->findByStatusAndCycleDate(
                    new Status(Status::TRANSFER_FAILED),
                    $previousDay
                );

        $transferSuccess = new Status(Status::TRANSFER_SUCCESS);
        $transferFailed = new Status(Status::TRANSFER_FAILED);

        foreach ($toTransfer as $operation) {
            try {
                $this->operationManager->save($operation);
                $transferId = $this->transferOperation(
                    $operation,
                    $this->generateLabel($publicLabelTemplate, $operation),
                    $this->generateLabel($privateLabelTemplate, $operation)
                );
                $operation->setStatus($transferSuccess);
                $operation->setTransferId($transferId);
            } catch (DispatchableException $e) {
                $operation->setStatus($transferFailed);
                $this->logger->warning(
                    $e->getMessage()
                );
                $this->dispatcher->dispatch(
                    $e->getEventName(), new ThrowException($e)
                );
            } catch (Exception $e) {
                $operation->setStatus($transferFailed);
                $this->logger->critical(
                    $e->getMessage()
                );
            }

            $this->operationManager->save($operation);
        }
    }
    /**
     * @param $previousDay
     * @param $withdrawLabelTemplate
     */
    public function withdrawOperations($previousDay, $withdrawLabelTemplate)
    {
        $toWithdraw = $this->operationManager->findByStatus(
            new Status(Status::TRANSFER_SUCCESS)
        );
        $toWithdraw = $toWithdraw + $this->operationManager
                ->findByStatusAndCycleDate(
                    new Status(Status::WITHDRAW_FAILED),
                    $previousDay
                );

        $withdrawRequested = new Status(Status::WITHDRAW_REQUESTED);
        $withdrawFailed = new Status(Status::WITHDRAW_FAILED);

        foreach ($toWithdraw as $operation) {
            try {
                $withdrawId = $this->withdrawOperation(
                    $operation,
                    $this->generateLabel($withdrawLabelTemplate, $operation)
                );
                $operation->setWithdrawId($withdrawId);
                $operation->setStatus($withdrawRequested);
            } catch (DispatchableException $e) {
                $operation->setStatus($withdrawFailed);
                $this->logger->warning(
                    $e->getMessage()
                );
                $this->dispatcher->dispatch(
                    $e->getEventName(),
                    new ThrowException($e)
                );
            } catch (Exception $e) {
                $this->logger->critical(
                    $e->getMessage()
                );
            }
            $this->operationManager->save($operation);
        }
    }

    /**
     * Transfer money between the technical
     * wallet and the operator|seller wallet
     *
     * @param OperationInterface $operation
     * @param string $publicLabel
     * @param string $privateLabel
     * @return int
     * @throws NoWalletFoundException
     */
    public function transferOperation(
        OperationInterface $operation,
        $publicLabel,
        $privateLabel
    )
    {
        $vendor = $this->vendorManager->findByHipayId($operation->getHipayId());

        if (!$vendor || $this->hipay->isAvailable($vendor->getEmail())) {
            throw new NoWalletFoundException($vendor);
        }

        $transfer = new Transfer(
            $operation->getAmount(),
            $vendor,
            $publicLabel,
            $privateLabel
        );
        //Transfer
        return $this->hipay->transfer($transfer);
    }

    /**
     * Put the money into the real bank account of the operator|seller
     *
     * @param OperationInterface $operation
     * @param $label
     * @return int
     * @throws WrongWalletBalance
     * @throws UnconfirmedBankAccountException
     * @throws UnidentifiedWalletException
     */
    public function withdrawOperation(OperationInterface $operation, $label)
    {
        $vendor = $this->vendorManager->findByHipayId($operation->getHipayId());

        if (!$this->hipay->isIdentified($vendor)) {
            throw new UnidentifiedWalletException($vendor);
        }

        $bankInfoStatus = $this->hipay->bankInfosStatus($vendor);
        if ($this->hipay->bankInfosStatus($vendor)
            != BankInfoStatus::VALIDATED
        ) {
            throw new UnconfirmedBankAccountException(
                $vendor,
                new BankInfoStatus($bankInfoStatus)
            );
        }

        //Check account balance
        $amount = $operation->getAmount();
        $balance = $this->hipay->getBalance($vendor);
        if ($balance < $amount) {
            if (!$operation->getMiraklId()) {
                $amount = $balance;
            } else {
                throw new WrongWalletBalance(
                    $vendor,
                    $amount,
                    $balance
                );
            }
        }
        //Withdraw
        return $this->hipay->withdraw($vendor, $amount, $label);
    }

    /**
     * Generate the label from a template
     *
     * @param $labelTemplate
     * @param $operation
     *
     * @return string
     */
    public function generateLabel($labelTemplate, OperationInterface $operation)
    {
        $m = new Mustache_Engine();
        return $m->render($labelTemplate, array(
            'miraklId' => $operation->getMiraklId(),
            'amount' => $operation->getAmount(),
            'hipayId' => $operation->getHipayId(),
            'cycleDate' => $operation->getCycleDate()->format('Y-m-d'),
            'cycleDateTime' => $operation->getCycleDate()->format(
                'Y-m-d H:i:s'
            ),
            'cycleTime' => $operation->getCycleDate()->format('H:i:s'),
            'date' => date('Y-m-d'),
            'datetime' => date('Y-m-d H:i:s'),
            'time' => date('H:i:s')
        ));
    }
}