# Loan Payment & Wallet System Documentation

## Overview
The Hayok Credit application supports flexible loan repayment through multiple payment channels:
1. **Wallet** - In-app wallet funded by deposits
2. **Card** - Saved payment cards for automatic charging

## Payment Flow

### Smart Payment Processing
When a user makes a loan repayment:
1. **Wallet Priority**: System first attempts to debit the wallet
2. **Fallback to Card**: If wallet balance is insufficient, the system automatically charges the user's saved card
3. **Error Handling**: If neither option is available, the payment fails with clear error messaging

```
User Payment Request
    ↓
Check Wallet Balance
    ├─ If sufficient → Debit Wallet → Success
    └─ If insufficient → Check Saved Cards
        ├─ If saved card exists & valid → Charge Card → Success
        └─ If no saved card → Error (suggest wallet top-up or card authorization)
```

## API Endpoints

### Wallet Management

#### View Wallet & Bank Details
```
GET /api/user/wallet

Response:
{
  "status": "success",
  "data": {
    "wallet": {
      "id": 1,
      "balance": "50000.00",
      "status": "active",
      "currency": "NGN",
      "transactions": [...]
    },
    "bank_details": {
      "bank_name": "GTBank",
      "bank_account_number": "****1234",
      "bank_account_name": "John Doe",
      "bank_code": "007"
    }
  }
}
```

#### View Wallet Transactions
```
GET /api/user/wallet/transactions?type=debit&limit=20&offset=0

Query Parameters:
- type: credit | debit (optional)
- limit: 1-100 (default: 20)
- offset: starting position (default: 0)

Response:
{
  "status": "success",
  "data": {
    "transactions": [
      {
        "id": 1,
        "type": "debit",
        "amount": "5000.00",
        "balance_before": "50000.00",
        "balance_after": "45000.00",
        "description": "Loan repayment",
        "reference": "ref-123",
        "status": "success",
        "created_at": "2026-09-07T10:30:00Z"
      }
    ],
    "pagination": {
      "total": 45,
      "limit": 20,
      "offset": 0,
      "has_more": true
    }
  }
}
```

#### View Withdrawal Requests
```
GET /api/user/wallet/withdrawals?status=pending&limit=20

Query Parameters:
- status: pending | processing | completed | failed | cancelled (optional)
- limit: 1-100 (default: 20)
- offset: starting position (default: 0)

Response:
{
  "status": "success",
  "data": {
    "withdrawals": [
      {
        "id": 1,
        "amount": "10000.00",
        "bank_name": "GTBank",
        "bank_account_number": "****1234",
        "status": "pending",
        "created_at": "2026-09-07T10:30:00Z"
      }
    ],
    "pagination": {...}
  }
}
```

#### Check Available Payment Methods
```
GET /api/user/wallet/payment-methods

Response:
{
  "status": "success",
  "data": {
    "payment_methods": [
      {
        "type": "wallet",
        "balance": "50000.00",
        "status": "active",
        "currency": "NGN",
        "available": true
      },
      {
        "type": "card",
        "id": 1,
        "brand": "Visa",
        "last4": "4242",
        "exp_month": 12,
        "exp_year": 2026,
        "status": "active",
        "available": true
      }
    ],
    "has_wallet": true,
    "has_card": true,
    "bank_details": {
      "bank_name": "GTBank",
      "bank_account_number": "****1234",
      "bank_account_name": "John Doe",
      "bank_code": "007"
    }
  }
}
```

#### Deposit to Wallet
```
POST /api/user/wallet/deposit

Request Body:
{
  "amount": 50000.00,
  "reference": "ref-deposit-123",
  "description": "Monthly top-up"
}

Response:
{
  "status": "success",
  "message": "Wallet funded successfully.",
  "data": {
    "wallet": {
      "balance": "100000.00",
      ...
    },
    "transaction": {
      "id": 2,
      "type": "credit",
      "amount": "50000.00",
      ...
    }
  }
}
```

#### Withdraw from Wallet
```
POST /api/user/wallet/withdraw

Requirements:
- Bank account must be connected first (POST /api/user/wallet/bank)

Request Body:
{
  "amount": 10000.00,
  "reference": "ref-withdraw-123",
  "description": "Withdrawal to bank"
}

Response:
{
  "status": "success",
  "message": "Withdrawal request created successfully.",
  "data": {
    "wallet": {...},
    "withdrawal": {
      "id": 1,
      "amount": "10000.00",
      "status": "pending"
    },
    "transaction": {
      "id": 3,
      "type": "debit",
      ...
    }
  }
}
```

#### Update Bank Details
```
POST /api/user/wallet/bank

Request Body:
{
  "bank_name": "GTBank",
  "bank_account_number": "0112631219",
  "bank_account_name": "John Doe",
  "bank_code": "007"
}

Response:
{
  "status": "success",
  "message": "Bank details updated successfully.",
  "data": {
    "bank_name": "GTBank",
    "bank_account_number": "0112631219",
    "bank_account_name": "John Doe",
    "bank_code": "007",
    "bank_connected_at": "2026-09-07T10:30:00Z"
  }
}
```

### Loan Repayment

#### Make Loan Payment (with Smart Payment Routing)
```
POST /api/user/loans/{loanId}/payments

Request Body:
{
  "amount_paid": 5000.00,
  "payment_reference": "ref-payment-123",
  "payment_method": "auto"  // "auto" (default), "wallet", or "card"
}

Response (Success - Wallet Charged):
{
  "status": "success",
  "message": "Installment paid successfully.",
  "data": {
    "id": 1,
    "loan_id": 1,
    "amount_paid": "5000.00",
    "status": "partial",
    "paid_at": "2026-09-07T10:30:00Z",
    "metadata": {
      "payment_method": "wallet"
    }
  }
}

Response (Success - Card Charged):
{
  "status": "success",
  "message": "Installment paid successfully.",
  "data": {
    "id": 1,
    "loan_id": 1,
    "amount_paid": "5000.00",
    "status": "partial",
    "paid_at": "2026-09-07T10:30:00Z",
    "payment_authorization_id": 1,
    "provider": "paystack",
    "metadata": {
      "payment_method": "card"
    }
  }
}

Response (Error - No Payment Method):
{
  "status": "error",
  "message": "Insufficient wallet balance. Please fund your wallet or authorize a card for automatic payment."
}
```

### Card Authorization

#### Initialize Card Authorization
```
POST /api/user/payments/paystack/initialize-card

Response:
{
  "status": "success",
  "data": {
    "reference": "card-auth-1-uuid",
    "transaction": {
      "reference": "card-auth-1-uuid",
      "authorization_url": "https://checkout.paystack.com/...",
      "access_code": "...",
      "public_key": "..."
    }
  }
}

User completes payment at authorization_url, then verifies:
```

#### Verify Card Authorization
```
GET /api/user/payments/paystack/verify/{reference}

Response:
{
  "status": "success",
  "data": {
    "id": 1,
    "user_id": 1,
    "card_type": "debit",
    "brand": "Visa",
    "last4": "4242",
    "exp_month": 12,
    "exp_year": 2026,
    "status": "active",
    "reusable": true
  }
}
```

#### List Saved Cards
```
GET /api/user/payment-authorizations

Response:
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "brand": "Visa",
      "last4": "4242",
      "exp_month": 12,
      "exp_year": 2026,
      "status": "active",
      "reusable": true
    }
  ]
}
```

#### Revoke Card Authorization
```
DELETE /api/user/payment-authorizations/{paymentAuthorizationId}

Response:
{
  "status": "success",
  "message": "Payment authorization revoked."
}
```

## Payment Method Priority

When processing loan repayments:
1. **Wallet** (Default) - Charged if balance is sufficient
2. **Card** (Fallback) - Automatically charged if:
   - Wallet balance is insufficient
   - User has at least one active, reusable card
   - Card authorization has not been revoked

## Database Schema Updates

### loan_payments Table Additions
- `payment_authorization_id` - Reference to saved card (nullable)
- `provider` - Payment provider (paystack)
- `provider_reference` - Provider transaction reference
- `provider_transaction_id` - Transaction ID from provider
- `amount_minor` - Amount in minor units (kobo)
- `failure_reason` - Reason for payment failure
- `last_attempt_at` - Timestamp of last payment attempt
- `next_retry_at` - Timestamp for next retry attempt
- `attempt_count` - Number of payment attempts
- `gateway_response` - Full response from payment gateway (JSON)
- `metadata` - Additional payment metadata (JSON)

## Error Handling

### Common Error Scenarios

**Insufficient Wallet & No Card:**
```json
{
  "status": "error",
  "message": "Insufficient wallet balance. Please fund your wallet or authorize a card for automatic payment."
}
```

**Wallet Not Found:**
```json
{
  "status": "error",
  "message": "Wallet not found. Verify your NIN or BVN and create a wallet first."
}
```

**Card Payment Failed:**
```json
{
  "status": "error",
  "message": "Card payment failed: [specific error from Paystack]"
}
```

**Bank Account Not Connected:**
```json
{
  "status": "error",
  "message": "Please connect your bank account before withdrawing funds."
}
```

## Automatic Scheduled Payments (Background Job)

There's an automatic scheduled job `ChargeDueLoanRepayment` that:
1. Runs on due dates for pending repayments
2. Checks if user has a saved card
3. Automatically charges the card without manual intervention
4. Retries failed payments based on configuration
5. Marks payments as overdue after max attempts

Configuration in `config/paystack.php`:
- `repayment_max_attempts` - Maximum retry attempts (default: 3)
- `repayment_retry_delay_hours` - Delay between retries (default: 24)

## Setup Instructions

1. **Create Wallet:** User initiates NIN/BVN verification
2. **Fund Wallet:** User deposits money via Paystack
3. **Connect Bank:** User adds bank account for withdrawals
4. **Add Card (Optional):** User authorizes a card for automatic payments
5. **Apply Loan:** User applies for loan (disbursed to bank account)
6. **Repay:** User makes monthly payments from wallet or card

## Transaction Flow Diagram

```
LOAN APPLICATION
    ↓
APPROVED BY ADMIN
    ↓
DISBURSED TO BANK ACCOUNT (via Paystack Transfer)
    ↓
REPAYMENT SCHEDULE CREATED
    ↓
PAYMENT DUE (Monthly)
    ↓
[Manual Payment]  OR  [Automatic Scheduled]
    ↓
SMART ROUTING:
├─ Check Wallet
│  ├─ Has Balance? → Debit Wallet
│  └─ No Balance? → Try Card
├─ Card Charge
│  ├─ Success? → Record Payment
│  └─ Failed? → Retry Later
└─ No Options? → Overdue
    ↓
UPDATE REPAYMENT SCHEDULE
    ↓
ALL PAID? → Mark Loan as Completed
```
