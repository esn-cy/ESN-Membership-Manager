<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Exception;
use PDO;

class ESNcardService
{
    protected ConfigFactoryInterface $configFactory;
    protected Connection $database;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;
    protected EmailManager $emailManager;
    protected WeeztixService $weeztixService;
    protected GoogleService $googleService;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        LoggerChannelFactoryInterface $logger_factory,
        StripeService                 $stripeService,
        EmailManager                  $emailManager,
        WeeztixService                $weeztixService,
        GoogleService                 $googleService
    )
    {
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->logger = $logger_factory->get('esn_membership_manager');
        $this->stripeService = $stripeService;
        $this->emailManager = $emailManager;
        $this->weeztixService = $weeztixService;
        $this->googleService = $googleService;
    }

    /**
     * Adds an array of new ESNcard numbers to the pool and processes the backlog.
     *
     * Validates each card number, inserts valid ones into the database,and then automatically attempts to fulfill
     * any previously backlogged applications using the newly available cards.
     *
     * @param array $cardNumbers An array of ESNcard numbers to insert.
     *
     * @return array An array of arrays describing any issues encountered during insertion. Each issue contains an
     * 'issue' type ('empty', 'invalid', 'duplicate', 'database') and the 'number' that failed.
     */
    public function addESNcards(array $cardNumbers): array
    {
        $issues = [];
        foreach ($cardNumbers as $cardNumber) {
            $trimmedNumber = trim($cardNumber);
            if (empty($trimmedNumber)) {
                $issues[] = ['issue' => 'empty', 'number' => $trimmedNumber];
                continue;
            }

            if (preg_match("/^\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]$/", $cardNumber) != 1) {
                $issues[] = ['issue' => 'invalid', 'number' => $trimmedNumber];
                continue;
            }

            $exists = $this->database->select('esn_membership_manager_cards', 'e')
                ->condition('number', $cardNumber)
                ->countQuery()
                ->execute()
                ->fetchField();
            if ($exists > 0) {
                $issues[] = ['issue' => 'duplicate', 'number' => $trimmedNumber];
                continue;
            }

            try {
                $this->database->insert('esn_membership_manager_cards')
                    ->fields([
                        'number' => $trimmedNumber,
                        'assigned' => 0,
                    ])
                    ->execute();
            } catch (Exception $e) {
                $this->logger->error('Failed to insert ESNcard @card: @message', [
                    '@card' => $cardNumber,
                    '@message' => $e->getMessage(),
                ]);
                $issues[] = ['issue' => 'database', 'number' => $trimmedNumber];
                continue;
            }
        }

        try {
            $applications = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a')
                ->condition('esncard_number', '%BACKLOGGED%', 'LIKE')
                ->orderBy('date_paid')
                ->execute()
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->logger->warning('Unable to check backlogged ESNcards: @message', ['@message' => $e->getMessage()]);
        }

        if (!empty($applications)) {
            $insertedCount = count($cardNumbers) - count($issues);
            if (count($applications) > $insertedCount) {
                $applications = array_slice($applications, 0, $insertedCount);
            }
            foreach ($applications as $application) {
                $transaction = $this->database->startTransaction();
                try {
                    $isManual = $application['esncard_number'] === 'BACKLOGGED-MANUAL';
                    $cardNumber = $this->assignESNcardNumber($application['id'], $isManual);

                    $this->database->update('esn_membership_manager_applications')
                        ->fields([
                            'esncard_number' => $cardNumber
                        ])
                        ->condition('id', $application['id'])
                        ->execute();

                    unset($transaction);

                    $application['esncard_number'] = $cardNumber;

                    $this->postAssignment($application, $isManual);
                } catch (Exception $e) {
                    if (isset($transaction)) {
                        $transaction->rollBack();
                    }
                    $this->logger->error('Failed to update application @id: @message', ['@id' => $application['id'], '@message' => $e->getMessage()]);
                }
            }
        }

        return $issues;
    }

    /**
     * Assigns the next available ESNcard number from the pool to an application.
     *
     * If no cards are available, it triggers an administrator notification and returns a backlog placeholder string.
     *
     * @param int $applicationID The ID of the application to assign the ESNcard to.
     * @param bool $isManual Indicates whether this assignment originates from a manual approval.
     *
     * @return string The assigned ESNcard number, or a 'BACKLOGGED' placeholder if the pool is empty.
     *
     * @throws Exception Thrown if there is a database failure while retrieving the next card.
     */
    public function assignESNcardNumber(int $applicationID, bool $isManual): string
    {
        try {
            $query = $this->database->select('esn_membership_manager_cards', 'e')
                ->fields('e', ['number'])
                ->condition('assigned', 0)
                ->orderBy('id')
                ->range(0, 1)
                ->forUpdate();

            /** @noinspection PhpPossiblePolymorphicInvocationInspection */
            $nextNumber = $query->execute()->fetchField();
        } catch (Exception $e) {
            $this->logger->error('Failed to assign ESNcard number: @message', ['@message' => $e->getMessage()]);
            throw new Exception("Failed to get next available ESNcard number: {$e->getMessage()}", null, $e);
        }

        if (empty($nextNumber)) {
            $alreadyBacklogged = $this->database->select('esn_membership_manager_applications', 'a')
                ->condition('esncard_number', '%BACKLOGGED%', 'LIKE')
                ->countQuery()
                ->execute()
                ->fetchField();

            if ($alreadyBacklogged == 0) {
                $this->emailManager->sendEmail('', 'admin_backlogged', []);
            }

            $this->logger->warning('No available ESNcard numbers left to assign.');
            return match ($isManual) {
                true => 'BACKLOGGED-MANUAL',
                false => 'BACKLOGGED',
            };
        }

        $this->database->update('esn_membership_manager_cards')
            ->fields(['assigned' => 1])
            ->condition('number', $nextNumber)
            ->execute();

        $this->logger->notice('Assigned ESNcard number @num to application @id.', [
            '@num' => $nextNumber,
            '@id' => $applicationID,
        ]);
        return $nextNumber;
    }

    /**
     * Executes external integrations after an ESNcard is successfully assigned.
     *
     * Depending on module configuration, this can add coupons to Weeztix, log the transaction in Google Sheets,
     * generate third-party Wallet passes, and send the final assignment email. Skips execution if the card is
     * backlogged.
     *
     * @param array $application An associative array containing the application object.
     * @param bool $isManual Indicates whether this assignment originated from a manual payment.
     */
    public function postAssignment(array $application, bool $isManual): void
    {
        if (str_contains($application['esncard_number'], 'BACKLOGGED')) {
            return;
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        if ($moduleConfig->get('switch_weeztix') ?? FALSE) {
            $this->weeztixService->addCoupon($application['esncard_number'], ['applies_to_count' => 1, 'usage_count' => 5]);
        }

        if ($moduleConfig->get('switch_google_sheets') ?? FALSE) {
            if (!$isManual) {
                $paymentMethod = 'Stripe';

                $isESNer = $application['mobility_status'] == 'ESN Volunteer' || $application['mobility_status'] == 'ESN Alumnus';
                try {
                    $priceFloat = $this->stripeService->getPriceAmount($isESNer);
                    $price = number_format($priceFloat, 2, '.', '');
                } catch (Exception) {
                    $price = 'Unknown';
                }
            } else {
                $paymentMethod = 'Manual';
                $price = 'Unknown';
            }

            $this->googleService->appendRow(
                [
                    'date' => str_replace('-', '/', date('d-m-y')),
                    'name' => $application['name'] . ' ' . $application['surname'],
                    'card_number' => $application['esncard_number'],
                    'pos' => 'ESN Membership Manager',
                    'host' => $application['host_institution'],
                    'nationality' => $application['nationality'],
                    'mop' => $paymentMethod,
                    'amount' => $price,
                ]
            );
        }

        if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
            $googleWalletLink = Url::fromRoute(
                'esn_membership_manager.add_to_google_wallet',
                ['identifier' => $application['esncard_number']],
                ['absolute' => TRUE]
            )->toString();
        }

        if ($moduleConfig->get('switch_apple_wallet') ?? FALSE) {
            $appleWalletLink = Url::fromRoute(
                'esn_membership_manager.download_apple_pass',
                ['identifier' => $application['esncard_number']],
                ['absolute' => TRUE]
            )->toString();
        }

        $emailParams = [
            'name' => $application['name'],
            'esncard_number' => $application['esncard_number'],
            'google_wallet_link' => $googleWalletLink ?? '',
            'apple_wallet_link' => $appleWalletLink ?? '',
        ];
        $this->emailManager->sendEmail($application['email'], 'card_assignment', $emailParams);
    }
}