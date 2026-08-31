<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Mail\BacklogEmail;
use Drupal\esn_membership_manager\Mail\CardAssignmentEmail;
use Drupal\omnia\Service\EmailService;
use Exception;

class ESNcardService
{
    protected MembershipSettings $membershipSettings;
    protected Connection $database;
    protected ApplicationStorage $applicationStorage;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;
    protected EmailService $emailService;
    protected WeeztixService $weeztixService;
    protected GoogleService $googleService;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        EntityTypeManagerInterface    $entityTypeManager,
        LoggerChannelFactoryInterface $loggerFactory,
        StripeService                 $stripeService,
        EmailService $emailService,
        WeeztixService                $weeztixService,
        GoogleService                 $googleService,
    )
    {
        /** @var ApplicationStorage $applicationStorage */
        $applicationStorage = $entityTypeManager->getStorage('membership_application');

        $this->membershipSettings = new MembershipSettings($configFactory);
        $this->database = $database;
        $this->applicationStorage = $applicationStorage;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->stripeService = $stripeService;
        $this->emailService = $emailService;
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

        $applications = $this->applicationStorage->getBacklogged();

        if (!empty($applications)) {
            $insertedCount = count($cardNumbers) - count($issues);
            if (count($applications) > $insertedCount) {
                $applications = array_slice($applications, 0, $insertedCount);
            }
            foreach ($applications as $application) {
                $transaction = $this->database->startTransaction();
                try {
                    $isManual = $application->getValue(ApplicationField::ESNcardNumber) === 'BACKLOGGED-MANUAL';
                    $cardNumber = $this->assignESNcardNumber($application, $isManual);

                    $application->setValue(ApplicationField::ESNcardNumber, $cardNumber);

                    $application->save();

                    unset($transaction);

                    $this->postAssignment($application, $isManual);
                } catch (Exception $e) {
                    if (isset($transaction)) {
                        $transaction->rollBack();
                    }
                    $this->logger->error('Failed to update application @id: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
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
     * @param ApplicationInterface $application The application entity to assign the ESNcard to.
     * @param bool $isManual Indicates whether this assignment originates from a manual approval.
     *
     * @return string The assigned ESNcard number, or a 'BACKLOGGED' placeholder if the pool is empty.
     *
     * @throws Exception Thrown if there is a database failure while retrieving the next card.
     */
    public function assignESNcardNumber(ApplicationInterface $application, bool $isManual): string
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
            $alreadyBacklogged = $this->applicationStorage->countBacklogged() > 0;

            if (!$alreadyBacklogged) {
                $email = new BacklogEmail();

                $this->emailService->send($this->membershipSettings->getAdminEmailAddress(), $email);
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
            '@id' => $application->id(),
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
     * @param ApplicationInterface $application The application entity.
     * @param bool $isManual Indicates whether this assignment originated from a manual payment.
     */
    public function postAssignment(ApplicationInterface $application, bool $isManual): void
    {
        if (str_contains($application->getValue(ApplicationField::ESNcardNumber), 'BACKLOGGED')) {
            return;
        }

        if ($this->membershipSettings->getWeeztixSwitch() && !empty($this->membershipSettings->getWeeztixCardCouponListID())) {
            $this->weeztixService->addCoupon('card', $application->getValue(ApplicationField::ESNcardNumber), ['applies_to_count' => 1, 'usage_count' => 5]);
        }

        if ($this->membershipSettings->getGoogleSheetsSwitch()) {
            if (!$isManual) {
                $paymentMethod = 'Stripe';

                $mobilityStatus = $application->getValue(ApplicationField::MobilityStatus);
                $isESNer = $mobilityStatus == 'ESN Volunteer' || $mobilityStatus == 'ESN Alumnus';
                try {
                    if ($priceFloat = $this->stripeService->getPriceAmount($isESNer)) {
                        $price = number_format($priceFloat, 2, '.', '');
                    } else {
                        $price = 'Unknown';
                    }
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
                    'name' => $application->getFullName(),
                    'card_number' => $application->getValue(ApplicationField::ESNcardNumber),
                    'pos' => 'ESN Membership Manager',
                    'host' => $application->getValue(ApplicationField::HostInstitution),
                    'nationality' => $application->getValue(ApplicationField::Nationality),
                    'mop' => $paymentMethod,
                    'amount' => $price,
                ]
            );
        }

        if ($this->membershipSettings->getGoogleWalletSwitch()) {
            $googleWalletLink = Url::fromRoute(
                'esn_membership_manager.add_to_google_wallet',
                ['identifier' => $application->getValue(ApplicationField::ESNcardNumber)],
                ['absolute' => TRUE]
            )->toString();
        }

        if ($this->membershipSettings->getAppleWalletSwitch()) {
            $appleWalletLink = Url::fromRoute(
                'esn_membership_manager.download_apple_pass',
                ['identifier' => $application->getValue(ApplicationField::ESNcardNumber)],
                ['absolute' => TRUE]
            )->toString();
        }

        $email = new CardAssignmentEmail(
            name: $application->getValue(ApplicationField::Name),
            cardNumber: $application->getValue(ApplicationField::ESNcardNumber),
            googleWalletLink: $googleWalletLink ?? null,
            appleWalletLink: $appleWalletLink ?? null,
        );
        $this->emailService->send($application->getValue(ApplicationField::Email), $email);
    }
}