
<?php

return [
    'agent_assigned' =>
        'Delivery agent assigned successfully.',

    'status_updated' =>
        'Delivery status updated successfully.',

    'access_denied' =>
        'You are not authorized to access deliveries.',

    'return_confirmed' =>
        'Physical return of the package confirmed.',

        
'assign_denied' =>
    'You are not authorized to assign a delivery agent.',

'pickup_order_cannot_assign' =>
    'This order is intended for in-store pickup.',

'order_cannot_assign' =>
    'This order can no longer be assigned for delivery.',

'reservation_expired' =>
    'The reservation for this order has expired.',

'agent_invalid' =>
    'The selected delivery agent is invalid or not authorized.',

'delivery_already_exists' =>
    'This order already has a delivery.',
    
'invalid_status' =>
    'Invalid delivery status.',

'update_denied' =>
    'You are not authorized to update this delivery.',

'not_assigned_to_user' =>
    'This delivery is not assigned to you.',

'order_cannot_be_delivered' =>
    'This order can no longer be delivered.',

'invalid_status_transition' =>
    'This delivery status transition is not allowed.',

'stock_reservation_expired' =>
    'The stock reservation has expired.',

'no_active_stock_reservation' =>
    'No active stock reservation was found.',

'payment_required_before_delivery' =>
    'Payment must be confirmed before completing the delivery.',

'failure_reason_required' =>
    'A failure reason between 1 and 1000 characters is required.',


    'return_confirmation_denied' => 'You are not authorized to confirm this return.',
    'return_notes_too_long' => 'Return notes must not exceed 1000 characters.',
    'return_delivery_order_mismatch' => 'The delivery does not match the order.',
    'return_order_not_eligible' => 'This order is not eligible for return.',
    'return_package_must_have_failed' => 'The package must have been picked up and then marked as failed.',
    'return_already_confirmed' => 'This return has already been confirmed.',
    'return_payment_status_unsupported' => 'This payment status is not supported.',
    'return_confirmed_payment_requires_review' => 'A confirmed payment requires financial handling.',
    'return_stock_reservations_inconsistent' => 'Stock reservations are missing or inconsistent.',
    'return_reservations_do_not_match_order' => 'The reservations do not match the order.',
    'return_reserved_quantity_inconsistent' => 'Reserved quantity is inconsistent.',
];
