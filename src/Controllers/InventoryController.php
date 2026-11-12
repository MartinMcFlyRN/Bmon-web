<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\Db;
use Bmon\Web\HttpException;
use Bmon\Web\Inventory;
use Bmon\Web\View;

/**
 * Inventory menu (list, create, edit, delete items) and assignment of items to hosts.
 * Everyone can view; changes are reserved to administrators (enforced by the routes).
 */
final class InventoryController
{
    public function index(): string
    {
        return View::render('inventory/index', [
            'title' => 'Inventory',
            'nav' => 'inventory',
            'items' => Inventory::allWithCounts(),
            'canEdit' => Auth::isAdmin(),
        ]);
    }

    public function newItem(array $errors = [], array $form = ['name' => '', 'description' => '']): string
    {
        return View::render('inventory/form', [
            'title' => 'New inventory item', 'nav' => 'inventory',
            'isNew' => true, 'form' => $form, 'errors' => $errors, 'hosts' => [],
        ]);
    }

    public function create(): string
    {
        [$name, $description] = $this->input();
        if ($errors = Inventory::validate($name, $description)) {
            http_response_code(422);
            return $this->newItem($errors, ['name' => $name, 'description' => $description]);
        }
        $id = Inventory::create($name, $description);
        Audit::log('inventory.create', "$name (id $id)");
        flash('success', "Item “{$name}” created.");
        redirect('/inventory');
    }

    public function show(int $id, array $errors = [], ?array $form = null): string
    {
        $item = $this->item($id);
        return View::render('inventory/form', [
            'title' => 'Inventory item ' . $item['name'], 'nav' => 'inventory',
            'isNew' => false, 'item' => $item, 'form' => $form ?? $item, 'errors' => $errors,
            'hosts' => Inventory::hosts($id), 'canEdit' => Auth::isAdmin(),
        ]);
    }

    public function update(int $id): string
    {
        $item = $this->item($id);
        [$name, $description] = $this->input();
        if ($errors = Inventory::validate($name, $description, $id)) {
            http_response_code(422);
            return $this->show($id, $errors, ['name' => $name, 'description' => $description]);
        }
        Inventory::update($id, $name, $description);
        Audit::log('inventory.update', "{$item['name']} (id $id)" . ($name !== $item['name'] ? " → $name" : ''));
        flash('success', "Item “{$name}” saved.");
        redirect('/inventory');
    }

    public function delete(int $id): never
    {
        $item = $this->item($id);
        $count = count(Inventory::hosts($id));
        Inventory::delete($id);
        Audit::log('inventory.delete', "{$item['name']} (id $id), removed from $count host(s)");
        flash('success', "Item “{$item['name']}” deleted" . ($count ? " and removed from $count host" . ($count === 1 ? '' : 's') : '') . '.');
        redirect('/inventory');
    }

    /** Old 0.4/0.5 URLs (/labels, /labels/{id}) keep working. */
    public function legacy(int $id = 0): never
    {
        header('Location: ' . url($id ? "/inventory/$id" : '/inventory'), true, 301);
        exit;
    }

    // --- host assignment (POST from the host page) ---------------------------

    public function assign(int $id): never
    {
        $host = $this->host($id);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $item = Inventory::find($itemId);
        if ($item === null) {
            flash('error', 'Choose an item to add.');
        } elseif (Inventory::assign($id, $itemId)) {
            Audit::log('host.inventory_add', "{$host['hostname']} (id $id): {$item['name']}");
            flash('success', "“{$item['name']}” added to the inventory of {$host['hostname']}.");
        }
        redirect('/status/' . $id);
    }

    public function unassign(int $id, int $itemId): never
    {
        $host = $this->host($id);
        $item = Inventory::find($itemId);
        if ($item !== null && Inventory::unassign($id, $itemId)) {
            Audit::log('host.inventory_remove', "{$host['hostname']} (id $id): {$item['name']}");
            flash('success', "“{$item['name']}” removed from the inventory of {$host['hostname']}.");
        }
        redirect('/status/' . $id);
    }

    // -------------------------------------------------------------------------

    private function input(): array
    {
        $s = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        return [$s('name'), str_replace("\r\n", "\n", $s('description'))];
    }

    private function item(int $id): array
    {
        return Inventory::find($id) ?? throw new HttpException(404, 'Inventory item not found.');
    }

    private function host(int $id): array
    {
        return Db::host($id)
            ?? throw new HttpException(404, 'Host not found.');
    }
}
