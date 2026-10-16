---
name: crm-leads-quotes
description: Sales/CRM module. Covers leads from site forms (quote request, callback, service request), B2B customers (companies + contacts), quotes (commercial offers) with line items and PDF, pipeline statuses, manager assignment, activity log, and conversion lead → quote → order. Use when building anything in admin "Sales".
---

# CRM: leads, customers, quotes

## Tables

- `customers`: id, type (enum: company/individual), name, inn (TIN, nullable, unique), phone, email, address, notes, manager_id (users), timestamps
- `contacts`: id, customer_id, name, position, phone, email, telegram
- `leads`: id, source (enum: quote_form/callback/service_request/checkout/telegram/manual), status (enum LeadStatus), name, phone, email, company, message, customer_id (nullable), manager_id (nullable), utm (json), locale, timestamps
- `lead_items`: lead_id, product_id (nullable), qty, note. Holds the products a client asked about
- `quotes`: id, number (e.g. `Q-2026-0001`), customer_id, lead_id (nullable), manager_id, status (enum QuoteStatus), currency (UZS), valid_until, subtotal, discount, total (tiyin), notes (json or text), timestamps
- `quote_items`: quote_id, product_id (nullable), name (snapshot), sku, qty, unit, price, discount, total
- `activities`: morph `subject`, user_id, type (note/call/email/status_change), body, created_at

## Statuses

- `LeadStatus`: new → in_progress → qualified → converted | lost (with a lost_reason)
- `QuoteStatus`: draft → sent → accepted → rejected | expired
- Accepting a quote creates an order through the `ConvertQuoteToOrder` action. That action reuses `orders` from `cart-checkout` with `source=quote`.

## Actions

- `CreateQuoteRequest`: from the site. Creates a lead and its items, matches or creates the customer by phone or INN, then dispatches `LeadCreated`, which triggers the Telegram notification.
- `AssignLead`, `ChangeLeadStatus` (logs an activity), `CreateQuoteFromLead`, `SendQuote` (PDF + email), `ConvertQuoteToOrder`

## Rules

- Phone numbers are normalized to `+998XXXXXXXXX` on save.
- Quote item prices are **snapshots**. Later product price changes don't affect them.
- Number sequences come from a `sequences` table using a row lock. Never use `max(id)+1`.
- Sales managers see only their own leads and customers plus unassigned ones. Admins see everything (enforced in the policy and the query scope).
- PDF: `barryvdh/laravel-dompdf` (ask before installing). The template is in the customer's locale.

## Admin screens

- Leads kanban or table, lead detail (activity timeline, convert button), customers, customer detail (contacts, leads, quotes, orders), quote builder (product picker, live totals) and a dashboard (new leads today, conversion, revenue).
