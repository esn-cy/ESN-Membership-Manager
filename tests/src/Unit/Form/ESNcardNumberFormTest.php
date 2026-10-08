<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\esn_membership_manager\Form\ESNcardNumberForm;
use Drupal\esn_membership_manager\Service\ESNcardService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;

/**
 * Unit tests for ESNcardNumberForm.
 *
 * @covers \Drupal\esn_membership_manager\Form\ESNcardNumberForm
 * @group esn_membership_manager
 */
class ESNcardNumberFormTest extends MembershipManagerTestCase
{
    private Connection $database;
    private ESNcardService $esncardService;
    private LoggerChannelInterface $logger;
    private MessengerInterface $messenger;
    private ESNcardNumberForm $form;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createMock(Connection::class);
        $this->container->set('database', $this->database);

        $this->esncardService = $this->createMock(ESNcardService::class);
        $this->container->set('esn_membership_manager.esncard_service', $this->esncardService);

        $this->logger = $this->createMock(LoggerChannelInterface::class);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($this->logger));

        $this->messenger = $this->createMock(MessengerInterface::class);
        $this->container->set('messenger', $this->messenger);

        $this->form = new ESNcardNumberForm(
            $this->database,
            $this->esncardService,
            $this->getLoggerFactoryMock($this->logger)
        );
        $this->form->setMessenger($this->messenger);
        $this->form->setStringTranslation($this->container->get('string_translation'));
    }

    public function testGetFormId(): void
    {
        $this->assertEquals('esncard_number_form', $this->form->getFormId());
    }

    public function testCreate(): void
    {
        $form = ESNcardNumberForm::create($this->container);
        $this->assertInstanceOf(ESNcardNumberForm::class, $form);
    }

    public function testSubmitForm(): void
    {
        $formArray = [];
        $formState = new FormState();
        $this->form->submitForm($formArray, $formState);
        $this->assertFalse($formState->hasAnyErrors());
    }

    public function testSubmitBulkInsertIgnoredWhenNotSubmitNew(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'other_button']);

        $this->messenger->expects($this->never())->method('addError');
        $this->form->submitBulkInsert($formArray, $formState);
    }

    public function testSubmitBulkInsertEmptyInput(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'submit_new']);
        $formState->setValue('cards', "   \n\r  ");

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('No ESNcard numbers provided.'));

        $this->form->submitBulkInsert($formArray, $formState);
    }

    public function testSubmitBulkInsertWithIssuesAndAllFailed(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'submit_new']);
        $formState->setValue('cards', "CARD1, CARD2\nCARD3");

        $issues = [
            ['issue' => 'invalid', 'number' => 'CARD1'],
            ['issue' => 'duplicate', 'number' => 'CARD2'],
            ['issue' => 'database', 'number' => 'CARD3'],
        ];

        $this->esncardService->expects($this->once())
            ->method('addESNcards')
            ->with(['CARD1', 'CARD2', 'CARD3'])
            ->willReturn($issues);

        $this->messenger->expects($this->exactly(2))
            ->method('addWarning');
        $this->messenger->expects($this->once())
            ->method('addError');
        $this->messenger->expects($this->never())
            ->method('addStatus');

        $this->form->submitBulkInsert($formArray, $formState);
        $this->assertFalse($formState->isRebuilding());
    }

    public function testSubmitBulkInsertSuccess(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'submit_new']);
        $formState->setValue('cards', "CARD1\nCARD2\nCARD3");

        $issues = [
            ['issue' => 'duplicate', 'number' => 'CARD2'],
        ];

        $this->esncardService->expects($this->once())
            ->method('addESNcards')
            ->with(['CARD1', 'CARD2', 'CARD3'])
            ->willReturn($issues);

        $this->messenger->expects($this->once())
            ->method('addWarning');
        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Inserted 2 ESNcard numbers.'));

        $this->form->submitBulkInsert($formArray, $formState);
        $this->assertTrue($formState->isRebuilding());
    }

    public function testDeleteESNcardIgnoredWhenNameDoesNotMatch(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'other_delete']);

        $this->database->expects($this->never())->method('delete');
        $this->form->deleteESNcard($formArray, $formState);
    }

    public function testDeleteESNcardDatabaseException(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement([
            '#name' => 'delete_42',
            '#esncard_id' => '42',
        ]);

        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willThrowException(new Exception('DB delete failed'));

        $this->database->expects($this->once())
            ->method('delete')
            ->with('esn_membership_manager_cards')
            ->willReturn($delete);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to delete ESNcard number with ID @number. Error: @error',
                ['@number' => '42', '@error' => 'DB delete failed']
            );

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with('DB delete failed');

        $this->form->deleteESNcard($formArray, $formState);
        $this->assertFalse($formState->isRebuilding());
    }

    public function testDeleteESNcardSuccess(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement([
            '#name' => 'delete_42',
            '#esncard_id' => '42',
        ]);

        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willReturn(1);

        $this->database->expects($this->once())
            ->method('delete')
            ->with('esn_membership_manager_cards')
            ->willReturn($delete);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Deleted ESNcard ID 42.'));

        $this->form->deleteESNcard($formArray, $formState);
        $this->assertTrue($formState->isRebuilding());
    }

    public function testUpdateESNcardIgnoredWhenNameDoesNotMatch(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'other_edit']);

        $this->database->expects($this->never())->method('select');
        $this->form->updateESNcard($formArray, $formState);
    }

    public function testUpdateESNcardEmptyValue(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement([
            '#name' => 'edit_5',
            '#esncard_id' => '5',
        ]);
        $formState->setValue(['esncards_table', '5', 'number'], '   ');

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('ESNcard number cannot be empty.'));

        $this->form->updateESNcard($formArray, $formState);
    }

    public function testUpdateESNcardCheckQueryException(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement([
            '#name' => 'edit_5',
            '#esncard_id' => '5',
        ]);
        $formState->setValue(['esncards_table', '5', 'number'], 'NEW123');

        $select = $this->createMock(SelectInterface::class);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willThrowException(new Exception('Select failed'));

        $this->database->expects($this->once())
            ->method('select')
            ->with('esn_membership_manager_cards', 'e')
            ->willReturn($select);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to update the ESNcard number: Select failed');

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Select failed'));

        $this->form->updateESNcard($formArray, $formState);
    }

    public function testUpdateESNcardDuplicateExists(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement([
            '#name' => 'edit_5',
            '#esncard_id' => '5',
        ]);
        $formState->setValue(['esncards_table', '5', 'number'], 'DUP123');

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn(1);

        $countQuery = $this->createMock(SelectInterface::class);
        $countQuery->method('execute')->willReturn($statement);

        $select = $this->createMock(SelectInterface::class);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturn($countQuery);

        $this->database->expects($this->once())
            ->method('select')
            ->with('esn_membership_manager_cards', 'e')
            ->willReturn($select);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('A duplicate ESNcard number already exists.'));

        $this->form->updateESNcard($formArray, $formState);
    }

    public function testUpdateESNcardUpdateThrowsException(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement([
            '#name' => 'edit_5',
            '#esncard_id' => '5',
        ]);
        $formState->setValue(['esncards_table', '5', 'number'], 'VALID123');

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn(0);

        $countQuery = $this->createMock(SelectInterface::class);
        $countQuery->method('execute')->willReturn($statement);

        $select = $this->createMock(SelectInterface::class);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturn($countQuery);

        $this->database->expects($this->once())
            ->method('select')
            ->with('esn_membership_manager_cards', 'e')
            ->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willThrowException(new Exception('Update failed'));

        $this->database->expects($this->once())
            ->method('update')
            ->with('esn_membership_manager_cards')
            ->willReturn($update);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to update ESNcard number @number. Error: @error',
                ['@number' => 'VALID123', '@error' => 'Update failed']
            );

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Update failed'));

        $this->form->updateESNcard($formArray, $formState);
    }

    public function testUpdateESNcardSuccess(): void
    {
        $formArray = [];
        $formState = new FormState();
        $formState->setTriggeringElement([
            '#name' => 'edit_5',
            '#esncard_id' => '5',
        ]);
        $formState->setValue(['esncards_table', '5', 'number'], 'VALID123');

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn(0);

        $countQuery = $this->createMock(SelectInterface::class);
        $countQuery->method('execute')->willReturn($statement);

        $select = $this->createMock(SelectInterface::class);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturn($countQuery);

        $this->database->expects($this->once())
            ->method('select')
            ->with('esn_membership_manager_cards', 'e')
            ->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->with(['number' => 'VALID123'])->willReturnSelf();
        $update->method('condition')->with('id', '5')->willReturnSelf();
        $update->method('execute')->willReturn(1);

        $this->database->expects($this->once())
            ->method('update')
            ->with('esn_membership_manager_cards')
            ->willReturn($update);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Updated ESNcard ID 5.'));

        $this->form->updateESNcard($formArray, $formState);
        $this->assertTrue($formState->isRebuilding());
    }
}
