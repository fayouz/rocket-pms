<?php

namespace App\Controller;

use App\Bilan\BilanBuilder;
use App\Entity\Expense;
use App\Entity\Property;
use App\Repository\ExpenseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bilan of a property (?year=AAAA, default the current year): Lodgify revenue + PMS accounting entries, JSON or CSV.
 * Entries: read by any user (PMS_READ), created, edited and deleted by an administrator (PMS_MANAGE).
 */
#[IsGranted('PMS_READ')]
final class BilanController extends AbstractController
{
    public function __construct(
        private readonly BilanBuilder $builder,
        private readonly ExpenseRepository $expenses,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/properties/{id}/bilan', name: 'api_property_bilan', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function bilan(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        return $this->json($this->builder->build($property, $this->year($request)) + ['categoryOptions' => self::categoryOptions()]);
    }

    #[Route('/api/properties/{id}/bilan.csv', name: 'api_property_bilan_csv', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function csv(#[MapEntity] Property $property, Request $request): Response
    {
        $year = $this->year($request);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $property->getName()) ?: 'logement')) ?? '', '-') ?: 'logement';

        return new Response($this->builder->csv($this->builder->build($property, $year)), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => \sprintf('attachment; filename="bilan-%s-%d.csv"', $slug, $year),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/api/properties/{id}/expenses', name: 'api_property_expenses', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function list(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $year = $this->year($request);
        $items = $this->expenses->between($property, new \DateTimeImmutable("$year-01-01"), new \DateTimeImmutable(($year + 1).'-01-01'));

        return $this->json(['year' => $year, 'items' => array_map(static fn (Expense $e) => $e->toArray(), $items), 'categoryOptions' => self::categoryOptions()]);
    }

    #[Route('/api/properties/{id}/expenses', name: 'api_property_expense_create', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('PMS_MANAGE')]
    public function create(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $data = $request->toArray();
        foreach (['date', 'amount', 'category'] as $required) {
            if (!\array_key_exists($required, $data)) {
                throw new HttpException(422, $required.' : valeur requise.');
            }
        }
        $expense = (new Expense($property))->apply($data);
        $this->em->persist($expense);
        $this->em->flush();

        return $this->json($expense->toArray(), 201);
    }

    #[Route('/api/expenses/{id}', name: 'api_expense_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('PMS_MANAGE')]
    public function update(#[MapEntity] Expense $expense, Request $request): JsonResponse
    {
        $expense->apply($request->toArray());
        $this->em->flush();

        return $this->json($expense->toArray());
    }

    #[Route('/api/expenses/{id}', name: 'api_expense_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('PMS_MANAGE')]
    public function delete(#[MapEntity] Expense $expense): Response
    {
        $this->em->remove($expense);
        $this->em->flush();

        return new Response(null, 204);
    }

    private function year(Request $request): int
    {
        $y = $request->query->getInt('year');

        return $y >= 2000 && $y <= 2100 ? $y : (int) $this->builder->today()->format('Y');
    }

    /** @return list<array{value: string, label: string, kind: string}> */
    private static function categoryOptions(): array
    {
        return array_map(static fn (string $k, array $v) => ['value' => $k, 'label' => $v[0], 'kind' => $v[1]], array_keys(Expense::CATEGORIES), Expense::CATEGORIES);
    }
}
