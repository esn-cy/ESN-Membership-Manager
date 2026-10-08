<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\esn_membership_manager\Controller\AddController;
use Drupal\esn_membership_manager\Service\ESNcardService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Drupal\esn_membership_manager\Controller\AddController
 * @group esn_membership_manager
 */
class AddControllerTest extends MembershipManagerTestCase
{
    public function testCreate(): void
    {
        $esncardService = $this->createMock(ESNcardService::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with('esn_membership_manager.esncard_service')
            ->willReturn($esncardService);

        $controller = AddController::create($container);
        $this->assertInstanceOf(AddController::class, $controller);
    }

    public function testAddCardSuccess(): void
    {
        $esncardService = $this->createMock(ESNcardService::class);
        $esncardService->expects($this->once())
            ->method('addESNcards')
            ->with(['1234567ABCD'])
            ->willReturn([]);

        $controller = new AddController($esncardService);
        $request = new Request([], [], [], [], [], [], json_encode(['card' => '1234567ABCD']));

        $response = $controller->addCard($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals(['status' => 'success', 'message' => 'The ESNcard was added successfully.'], $data);
    }

    /**
     * @dataProvider provideAddCardIssues
     */
    public function testAddCardIssues(string $issue, int $expectedStatusCode, string $expectedMessage): void
    {
        $esncardService = $this->createMock(ESNcardService::class);
        $esncardService->expects($this->once())
            ->method('addESNcards')
            ->willReturn([['issue' => $issue]]);

        $controller = new AddController($esncardService);
        $request = new Request([], [], [], [], [], [], json_encode(['card' => '1234567ABCD']));

        $response = $controller->addCard($request);
        $this->assertEquals($expectedStatusCode, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals(['status' => 'error', 'message' => $expectedMessage], $data);
    }

    public function provideAddCardIssues(): array
    {
        return [
            'empty issue' => ['empty', 400, 'No ESNcard number was provided.'],
            'invalid issue' => ['invalid', 400, 'Invalid ESNcard number was provided.'],
            'duplicate issue' => ['duplicate', 409, 'This ESNcard number already exists.'],
            'database issue' => ['database', 500, 'There was a problem inserting the card.'],
            'default issue' => ['something_else', 500, 'Unexpected error occurred.'],
        ];
    }
}
