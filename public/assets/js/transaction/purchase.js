$(document).ready(function(){
    $(document).on('input','.item-units, .item-unit-price, .item-discount',function() {
         var amount = $(this).val().replace(/[^\d.]/g, '');

        // Remove leading zeros
        amount = amount.replace(/^0+(?!\.|$)/, '');

        // Allow only one decimal point
        if (amount.indexOf('.') !== -1) {
            // Remove any additional decimal points
            amount = amount.substring(0, amount.indexOf('.') + 1) + amount.substring(amount.indexOf('.') + 1).replace(/\./g, '');
        }

        $(this).val(amount);
    }).on('focusout','.item-unit-price, .item-discount',function() {
        var amount = 0;
        amount = parseFloat($(this).val());

        $(this).val(amount.toFixed(2));
    }).on('focusout','.item-units',function() {
        var amount = 0;
        amount = parseFloat($(this).val());

        $(this).val(amount);
    });

    $('#additem').on('change', function(){
        if ($(this).is(':checked')) {
            $('#item-list').removeClass('d-none');
            $('#purchase #amount').attr('disabled',true);
            updateTotalAmount();
            
        } else {
            $('#item-list').addClass('d-none');
            $('#purchase #amount').attr('disabled',false);
        }
    });

    var rowCount = 0;

    $('#addRowBtn').on('click', function() {
        rowCount++;
        var newRow = `
            <tr id="table-row-${rowCount}">
                <td><input type="text" id="name-${rowCount}" data-index-id="${rowCount}" class="form-control item-name" placeholder="Name"></td>
                <td><input type="text" id="units-${rowCount}" class="form-control item-units" placeholder="Units" value="1"></td>
                <td><input type="text" id="unit-price-${rowCount}" class="form-control item-unit-price" placeholder="Unit Price" value="0.00"></td>
                <td>
                    <input type="checkbox" id="discount-per-unit-${rowCount}" class="form-check-input discount-per-unit" value="discount-per-unit">
                </td>
                <td><input type="text" id="discount-${rowCount}" class="form-control item-discount" placeholder="Discount" value="0.00"></td>
                <td><button data-handle-row="${rowCount}" data-final-price-${rowCount}="0.00" id="removeRowBtn-${rowCount}" class="final-price btn btn-danger removeRowBtn">Remove</button></td>
            </tr>
            <tr class="d-none" id="error-msg-${rowCount}">
                <td colspan="6"><span class="error"></span></td>
            </tr>
        `;
        $('#dataTable tbody').append(newRow);
    });

    $(document).on('input', '.item-units, .item-unit-price, .item-discount, .discount-per-unit', function() {
        calculateItemList();
    }).on('focusout','.item-name',function(){
        var index = $(this).data('index-id');
        if($(this).val() == ''){
            $(`#error-msg-${index} .error`).text('* Name is required');
            $(`#error-msg-${index}`).removeClass('d-none');
            $(`#units-${index}`).val('1');
            $(`#unit-price-${index}`).val('0.00');
            $(`#discount-${index}`).val('0.00');
            return;
        }
        $(`#error-msg-${index}`).addClass('d-none');

    });

    function calculateItemList(){
        var units = 0;
        var unitPrice = 0;
        var discount = 0;
        var finalPrice = 0;

        for (let index = 1; index <= rowCount; index++) {
            console.log(index);
            if($(`#name-${index}`).val() == ''){
                $(`#error-msg-${index} .error`).text('* Name is required');
                $(`#error-msg-${index}`).removeClass('d-none');
                $(`#units-${index}`).val('1');
                $(`#unit-price-${index}`).val('0.00');
                $(`#discount-${index}`).val('0.00');
                continue;
            }
            units = parseFloat($(`#units-${index}`).val()) || 0;
            unitPrice = parseFloat($(`#unit-price-${index}`).val()) || 0;
            discount = parseFloat($(`#discount-${index}`).val()) || 0;

            if($(`#discount-per-unit-${index}`).is(':checked')){
                discount = (discount * units);
            }

            if((units * unitPrice) < discount){
                $(`#discount-${index}`).val(0);
                discount = 0;
            }
            finalPrice = (units * unitPrice) - discount;

            $(`#removeRowBtn-${index}`).attr('data-final-price', finalPrice.toFixed(2));
                    
            updateTotalAmount();
            console.log(`Row ${index} Final Price: ${finalPrice.toFixed(2)}`);
        }
    }

    function updateTotalAmount(){
        var totalAmount = 0;
        var today = new Date(); 

        // Loop through each final price element and sum them
        $('.final-price').each(function() {
            totalAmount += parseFloat($(this).attr('data-final-price')) || 0;
        });

        // Update the input with id 'amount'
        $('#purchase-error').addClass('d-none');
        $('#purchase #amount').val(totalAmount.toFixed(2));
        $('#purchase #partialamount').val(totalAmount.toFixed(2));
    }

    $('#purchase-btn').on('click',function(){
        var item_list = [];
        var item = [];
        
        if($('#purchase #amount').val() == '' || $('#purchase #amount').val() <= 0){
            $('#common-error').text('* Amount should be larger than 0');
            $('#common-error').removeClass('d-none');
            return;
        }

        var name = '';
        var units = 0;
        var unit_price = 0;
        var discount = 0;

        if($('#additem').is(':checked')){
            for (let index = 1; index <= rowCount; index++) {
                if($(`#name-${index}`).val() == ''){
                    continue;
                }

                name = $(`#name-${index}`).val();
                units = parseFloat($(`#units-${index}`).val()) || 0;
                unit_price = parseFloat($(`#unit-price-${index}`).val()) || 0;
                discount = parseFloat($(`#discount-${index}`).val()) || 0;

                if($(`#discount-per-unit-${index}`).is(':checked')){
                    discount = (discount * units);
                }
            
                finalPrice = (units * unit_price) - discount;

                if(finalPrice == 0 && discount == 0){
                    continue;
                }

                item = {
                    'name': name,
                    'units': units,
                    'unit_price': unit_price,
                    'discount' : discount
                };

                item_list.push(item);

                item = [];

            }
        }

        var today = new Date().toISOString().slice(0, 10);
        var scheduled_info = '';

        if($('#schedule-payment-checkbox').is(':checked')){
            scheduled_info = {
                start_date : $('#payment-plan-start-date').val() || today,
                period: ($('#payment-plan-type').val() != 'I') ? $('#scheduled-payment-period').val() : 1,
                schedule_type: $('#payment-plan-type').val() || 'I',
                make_initial_payment: $('#init-payment').is(':checked') ? 1 : 0
            };
        }

        var $purchase = $('#purchase');

        LB.post('js-request/payment/purchase', {
            amount: $purchase.find('#amount').val(),
            description: $purchase.find('#description').val(),
            current_account: $purchase.find('#current-account-list').val(),
            payment_method: LB.paymentMethodOf($purchase),
            reference: $purchase.find('#reference').val(),
            budget: $purchase.find('#budget-list').val(),
            shop: $purchase.find('#shop-list').val(),
            item_list: (item_list.length > 0) ? item_list : '',
            scheduled_info: scheduled_info
        }, function () {
            window.location.reload();
        }, null, $(this));
    });

    // Handle the remove button
    $('#dataTable').on('click', '.removeRowBtn', function() {
        var row_id = $(this).data('handle-row');
        
        $(`#table-row-${row_id}`).remove();
        $(`#error-msg-${row_id}`).remove();
        updateTotalAmount();
    });
});