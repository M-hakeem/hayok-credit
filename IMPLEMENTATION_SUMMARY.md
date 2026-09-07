# Loan Repayment & Wallet System Implementation Summary

## Implementation Date
September 7, 2026

## Overview
Successfully implemented a comprehensive payment system with automatic card charging fallback when wallet balance is insufficient.

## Files Created

### 1. **PaymentService** 
**Path:** `app/Services/PaymentService.php`

**Purpose:** Core payment processing logic with smart routing

**Key Methods:**
- `processLoanPayment()` - Main payment processing method
  - Validates payment amount
  - Attempts wallet debit first
  - Falls back to card charging if wallet insufficient
  - Creates payment record with transaction history
  - Updates repayment schedule status

**Features:**
- Database transaction for atomicity
- Comprehensive error handling
- Support for both wallet and card payments
- Automatic schedule and loan status updates

---

## Files Modified

### 1. **LoanPaymentController**
**Path:** `app/Http/Controllers/LoanPaymentController.php`

**Changes:**
- Added `PaymentService` dependency injection
- Updated `store()` method to use new payment service
- Enhanced payment method validation
- Improved error messages for payment failures

**New Validation:**
```php
'payment_method' => 'nullable|in:wallet,card,auto',
```

---

### 2. **WalletController**
**Path:** `app/Http/Controllers/WalletController.php`

**New Methods Added:**

#### `transactions()`
- View wallet transaction history
- Supports filtering by transaction type (credit/debit)
- Pagination support (limit, offset)

#### `withdrawals()`
- View all withdrawal requests for user
- Filter by withdrawal status
- Pagination support

#### `paymentMethods()`
- List all available payment methods (wallet + cards)
- Shows wallet balance and status
- Shows saved card details (brand, last4, exp)
- Indicates availability of each method

---

### 3. **API Routes**
**Path:** `routes/api.php`

**New Routes:**
```php
GET    /api/user/wallet/transactions      # View transaction history
GET    /api/user/wallet/withdrawals       # View withdrawal requests
GET    /api/user/wallet/payment-methods   # Check available payment methods
```

---

## Database Schema

### Existing `loan_payments` Table Columns Used
The implementation leverages existing columns in the `loan_payments` table:

```
- payment_authorization_id    # Link to saved card
- provider                     # Payment provider (paystack)
- provider_reference          # Provider transaction ID
- provider_transaction_id     # Transaction ID
- amount_minor                # Amount in minor units (kobo)
- failure_reason              # Payment failure reason
- last_attempt_at             # Timestamp of last attempt
- next_retry_at               # Next retry timestamp
- attempt_count               # Number of attempts
- gateway_response            # Provider response (JSON)
- metadata                    # Additional metadata (JSON)
```

All columns were already created by migrations:
- `2026_05_14_120001_create_loan_payments_table.php`
- `2026_09_05_000002_add_paystack_fields_to_loan_payments_table.php`

---

## Smart Payment Flow

### Payment Processing Logic

```
User Initiates Payment
    ↓
[PaymentService::processLoanPayment()]
    ├─ Validate amount
    ├─ Check if amount ≤ schedule.balance_due
    ├─ Try Wallet Payment
    │  └─ If wallet balance ≥ amount_paid
    │     └─ Debit wallet → Success
    ├─ If wallet fails or insufficient
    │  └─ Get user's active payment authorization
    │     ├─ If found and isUsable()
    │     │  └─ Charge card via Paystack → Success
    │     └─ If not found
    │        └─ Return Error
    ├─ Create LoanPayment record
    ├─ Update RepaymentSchedule
    └─ Update Loan status if all paid
```

### Payment Priority Matrix

| Scenario | Wallet Balance | Card Available | Action | Result |
|----------|---|---|--------|--------|
| Sufficient wallet | ✓ | ✓/✗ | Use wallet | ✓ Success |
| Insufficient wallet | ✗ | ✓ | Use card | ✓ Success |
| Insufficient wallet | ✗ | ✗ | None available | ✗ Error |
| No wallet | ✗ | ✓ | Use card | ✓ Success |
| No wallet | ✗ | ✗ | None available | ✗ Error |

---

## API Usage Examples

### 1. Make Loan Payment (Auto-Routing)
```bash
POST /api/user/loans/{loanId}/payments

Body:
{
  "amount_paid": 5000.00,
  "payment_reference": "ref-123"
}

# System will:
# 1. Check if wallet has ₦5000
# 2. If yes → debit wallet
# 3. If no → check for saved card
# 4. If card exists → charge it
# 5. If neither → return error
```

### 2. View Available Payment Methods
```bash
GET /api/user/wallet/payment-methods

Response:
{
  "status": "success",
  "data": {
    "payment_methods": [
      {
        "type": "wallet",
        "balance": "50000.00",
        "available": true
      },
      {
        "type": "card",
        "brand": "Visa",
        "last4": "4242",
        "available": true
      }
    ],
    "has_wallet": true,
    "has_card": true
  }
}
```

### 3. View Transaction History
```bash
GET /api/user/wallet/transactions?type=debit&limit=20

Response:
{
  "status": "success",
  "data": {
    "transactions": [
      {
        "type": "debit",
        "amount": "5000.00",
        "balance_before": "55000.00",
        "balance_after": "50000.00",
        "description": "Loan repayment",
        "created_at": "2026-09-07T10:30:00Z"
      }
    ],
    "pagination": {
      "total": 45,
      "limit": 20,
      "has_more": true
    }
  }
}
```

### 4. Authorize Card for Auto-Payment
```bash
POST /api/user/payments/paystack/initialize-card

Response:
{
  "status": "success",
  "data": {
    "reference": "card-auth-1-uuid",
    "transaction": {
      "authorization_url": "https://checkout.paystack.com/..."
    }
  }
}

# User completes payment, then verifies:

GET /api/user/payments/paystack/verify/{reference}

Response:
{
  "status": "success",
  "data": {
    "id": 1,
    "brand": "Visa",
    "last4": "4242",
    "status": "active",
    "reusable": true
  }
}
```

---

## Error Handling

### Common Error Responses

**1. Insufficient Balance & No Card**
```json
{
  "status": "error",
  "message": "Insufficient wallet balance. Please fund your wallet or authorize a card for automatic payment."
}
```

**2. Card Payment Failed**
```json
{
  "status": "error",
  "message": "Card payment failed: [specific error from Paystack]"
}
```

**3. Payment Exceeds Due Amount**
```json
{
  "status": "error",
  "message": "Payment amount cannot exceed the installment balance."
}
```

**4. Loan Not Found**
```json
{
  "status": "error",
  "message": "Loan not found."
}
```

---

## Automatic Scheduled Payments

### Background Job: `ChargeDueLoanRepayment`
Already implemented in: `app/Jobs/ChargeDueLoanRepayment.php`

**Functionality:**
1. Runs for each due repayment schedule
2. Checks if payment already made
3. Finds user's active payment authorization
4. Automatically charges card without manual intervention
5. Retries failed payments on schedule
6. Logs all attempts for audit trail

**Configuration** (in `config/paystack.php`):
```php
'repayment_max_attempts' => 3,              // Max retry attempts
'repayment_retry_delay_hours' => 24,        // Delay between retries
'authorization_amount_kobo' => 10000,       // ₦100 for card auth
```

---

## Testing Checklist

- [ ] Test wallet payment with sufficient balance
- [ ] Test card payment with insufficient wallet
- [ ] Test error when neither wallet nor card available
- [ ] Test transaction history endpoint
- [ ] Test withdrawals endpoint
- [ ] Test payment methods endpoint
- [ ] Test automatic card charging on due date
- [ ] Test payment retry logic
- [ ] Test repayment schedule updates
- [ ] Test loan completion when all paid

---

## Deployment Steps

1. **Verify Migrations**
   ```bash
   php artisan migrate:status
   ```

2. **Clear Cache**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

3. **Test Payment Endpoints**
   ```bash
   # Make a test payment
   POST /api/user/loans/1/payments
   ```

4. **Monitor Logs**
   ```bash
   tail -f storage/logs/laravel.log
   ```

5. **Verify Queue Jobs** (if using Redis/database queues)
   ```bash
   php artisan queue:work
   ```

---

## Key Features Implemented

✅ **Smart Payment Routing** - Wallet first, card fallback
✅ **Automatic Fallback** - No user intervention needed
✅ **Transaction Audit Trail** - All transactions logged
✅ **Payment Method Discovery** - Users can see available methods
✅ **Withdrawal Management** - View all withdrawal requests
✅ **Error Handling** - Clear, actionable error messages
✅ **Database Atomicity** - Transactional updates
✅ **Paystack Integration** - Existing services leveraged
✅ **Pagination** - Large dataset handling
✅ **Security** - Encrypted sensitive data

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                        User                                 │
└────────────────────┬──────────────────────────────────────┘
                     │
                     ├─ POST /api/user/loans/{id}/payments
                     │
┌────────────────────▼──────────────────────────────────────┐
│           LoanPaymentController::store()                   │
└────────────────────┬──────────────────────────────────────┘
                     │
                     ├─ Validates payment amount
                     ├─ Finds repayment schedule
                     │
┌────────────────────▼──────────────────────────────────────┐
│        PaymentService::processLoanPayment()                │
└────────┬───────────────────────┬──────────────────────────┘
         │                       │
         ▼                       ▼
   ┌──────────────┐      ┌──────────────────┐
   │ Wallet Debit │      │ Card Charging    │
   │  (Primary)   │      │  (Fallback)      │
   └──────┬───────┘      └────────┬─────────┘
          │                       │
          │ Insufficient          │
          ├──────────────────────►│
          │                       │
          ▼                       ▼
   ┌────────────────────────────────────────┐
   │   LoanPayment Record Created           │
   │   RepaymentSchedule Updated            │
   │   Loan Status Updated                  │
   └────────────────┬───────────────────────┘
                    │
                    ▼
            Response to User
```

---

## Related Documentation

- `PAYMENT_SYSTEM_GUIDE.md` - Complete API documentation
- `app/Services/PaymentService.php` - Payment processing logic
- `app/Services/Paystack/RepaymentService.php` - Card charging service
- `app/Services/Paystack/PaymentAuthorizationService.php` - Card authorization

---

## Support & Maintenance

### Monitoring
- Monitor payment failures in logs
- Check Paystack webhook responses
- Review repayment schedule status
- Track payment authorization issues

### Common Issues
1. **Card Declined** → User needs to update card via authorization flow
2. **Wallet Empty** → User needs to deposit funds
3. **Payment Delayed** → Check queue workers are running
4. **Authorization Expired** → User needs to re-authorize card

---

## Future Enhancements

- [ ] Add payment analytics dashboard
- [ ] Implement payment reminders (email/SMS)
- [ ] Add multiple card support priority
- [ ] Payment plan modifications
- [ ] Partial payment tracking improvements
- [ ] Mobile app integration
- [ ] WebSocket real-time payment updates
