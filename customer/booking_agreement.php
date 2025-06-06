<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}
include '../connect.php';
include '../includes/header.php';

// Ensure driver and guarantor data exist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guarantor_full_name'])) {
    // Store guarantor data in session (to be used in final booking_submit.php)
    $_SESSION['guarantor_data'] = [
        'guarantor_full_name'    => $_POST['guarantor_full_name'] ?? '',
        'guarantor_phone_no'     => $_POST['guarantor_phone_no'] ?? '',
        'guarantor_id_no'        => $_POST['guarantor_id_no'] ?? '',
        'guarantor_relationship' => $_POST['guarantor_relationship'] ?? '',
    ];
    // Handle uploaded images (store in session temporarily as file paths)
    if (isset($_FILES['guarantor_id_front']) && $_FILES['guarantor_id_front']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['guarantor_id_front']['tmp_name'];
        $name = uniqid('guar_idfront_') . '_' . basename($_FILES['guarantor_id_front']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['guarantor_data']['guarantor_id_front'] = $dest;
    }
    if (isset($_FILES['guarantor_id_back']) && $_FILES['guarantor_id_back']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['guarantor_id_back']['tmp_name'];
        $name = uniqid('guar_idback_') . '_' . basename($_FILES['guarantor_id_back']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['guarantor_data']['guarantor_id_back'] = $dest;
    }
} elseif (!isset($_SESSION['guarantor_data'])) {
    header("Location: booking_guarantor.php");
    exit;
}

// Terms text for agreement
$agreement_terms = <<<EOT
AGREEMENT OF VEHICLE USAGE BETWEEN BORROWER AND TIMELESS CAR RENTAL

TimeLess Car Rental is a brand operated by TimeLess Car Rental. Attached herewith are the terms and conditions that shall be between the BORROWER of the vehicle and TimeLess Car Rental. When the agreement is signed, the BORROWER has acknowledged that he/she has read, understood and agreed to the terms and conditions.

IT IS HEREBY AGREED AS FOLLOWS:
1. The consolation loan agreed for this vehicle is as per agreed in the quotation. No claim will be made by the BORROWER if the vehicle is returned earlier than the promised expiry date and time.
2. TimeLess Car Rental reserves the right to claim additional consolation if the vehicle is returned late after the expiry of the LOAN as above. For vehicles with a daily rate of less than RM300, the value claimed is RM25/hour. For vehicles with a daily rate of more than RM300, the claimed value is RM60/hour. TimeLess Car Rental also has the right to exercise discretion in determining the level of demand for this clause (2).
3. Any loan extension must be notified to TimeLess Car Rental at least 3 hours in advance, subject to the availability of the vehicle. BORROWER agrees to all terms and conditions agreed in this agreement for the duration of the extension period. Only ONE (1) EXTENSION is allowed. For the next loan extension, the BORROWER must be present at the TimeLess Car Rental office with the vehicle for approval.
4. This vehicle is not used for any kind of criminal activities or offences under the laws of Malaysia.
5. THE BORROWER is fully responsible in the event of misuse of this vehicle such as being involved in any kind of criminal activities or offences under the laws of Malaysia.
6. It is a responsibility for the BORROWERS to pay all summonses, compounds, or fines within the LOAN tenure as above.
7. TimeLess Car Rental reserves the right to claim any compensation in the event of any damage/accident/replacement of spare parts without TimeLess Car Rental's consent involving the vehicle during the LOAN period as above.
8. If the vehicle is not found/lost/severely damaged after the LOAN period of this vehicle as above, the BORROWER is responsible for all costs and claims incurred by TimeLess Car Rental involving this vehicle.
9. TimeLess Car Rental reserves the right to request/keep a copy of the Identity Card/Driver's License/Student Card/Employee Card or any supporting documents of this vehicle BORROWER/GUARANTOR for the purpose of further action related to all processes related to the above vehicle rental.
10. THE BORROWER will be responsible for any issues arising for the duration of the loan if such issues arise after the return of the deposit. TimeLess Car Rental reserves the right to make claims against borrowers to resolve such issues.
11. The vehicle is provided with full fuel. THE BORROWER is responsible for returning the vehicle in a state of full oil. Failure will entitle TimeLess Car Rental to claim RM100 for refuelling and service charges.
12. TimeLess Car Rental reserves the right to contact the Guarantor given by the BORROWER for the purpose of resolving any issues arising in relation to the vehicle loan if the BORROWER is unable to resolve the arising issues. This is in line with the consent that has been given by the GUARANTOR.
13. Only BORROWERs are allowed to drive the above vehicles. Third parties are not allowed to drive the above vehicles.
14. The BORROWER acknowledges that TimeLess Car Rental does not provide any personal accident and damage/loss of property insurance to the BORROWER. The BORROWER is solely responsible for the personal safety and property of the BORROWER.
15. THE BORROWER AND GUARANTOR testified and acknowledge that the TimeLess Car Rental REPRESENTATIVE had shown that each compartment in the parts of the vehicle was empty/did not contain any prohibited goods in violation of Malaysian laws. The BORROWER and GUARANTOR release TimeLess Car Rental and its representatives from any legal claims if any prohibited items are found by the authorities, in the vehicle for the entire period of use of the vehicle by the BORROWER and GUARANTOR.
16. The agreed security deposit is as per agreed in the quotation. The security deposit is taken for wagering purposes for any breach of conditions and/or driving outside the stated destination and/or payment of part of the summons issue by the BORROWER during the loan tenure. The security deposit will be refunded (either in full or the balance after deduction if an issue arises) to the BORROWER on/after FIVE(5) WORKING DAYS after the vehicle has been returned to TimeLess Car Rental.
EOT;
?>

<link rel="stylesheet" href="/assets/css/style.css">
<style>
.agreement-section {
    max-width: 650px;
    margin: 40px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 28px 32px 24px 32px;
}
.agreement-title {
    font-size: 1.25em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 18px;
}
.agreement-terms {
    font-size: 1em;
    color: #2d2d2d;
    background: #f7f8fa;
    padding: 14px 15px;
    border-radius: 7px;
    height: 260px;
    overflow-y: auto;
    margin-bottom: 18px;
    white-space: pre-line;
}
.input-row {
    margin-bottom: 16px;
}
input[type="checkbox"] {margin-right: 8px;}
.next-btn {
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 12px 30px;
    border-radius: 7px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    margin-left: 8px;
}
.next-btn:hover {background: #234c96;}
.back-btn {
    background: #ccc;
    color: #222;
    border: none;
    padding: 12px 30px;
    border-radius: 7px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
}
.back-btn:hover {background: #bbb;}
#signature-pad {
    width: 100%;
    height: 130px;
    border: 1.5px solid #bfc4e1;
    border-radius: 7px;
    background: #fff;
    margin-top: 6px;
}
.sig-label {
    font-weight: 600;
    color: #444;
}
.btn-row {
    margin-top: 28px;
    text-align: right;
}
</style>

<div class="agreement-section">
    <div class="agreement-title">Rental Agreement & Signature</div>
    <form action="booking_submit.php" method="POST" onsubmit="return submitAgreementForm();" enctype="multipart/form-data">
        <div class="agreement-terms"><?= nl2br(htmlspecialchars($agreement_terms)) ?></div>
        <div class="input-row">
            <label>
                <input type="checkbox" id="agree" name="agree" value="1" required>
                I have read and agree to all terms and conditions above.
            </label>
        </div>
        <div class="input-row">
            <label class="sig-label">Signature (draw below):</label>
            <canvas id="signature-pad"></canvas>
            <input type="hidden" name="signature_data" id="signature_data" required>
            <div style="margin-top:7px;">
                <button type="button" onclick="clearSignaturePad();" style="font-size:0.98em;padding:4px 18px;">Clear</button>
            </div>
        </div>
        <div class="btn-row">
            <a href="review_booking.php" class="back-btn" style="text-decoration: none; display: inline-block;">Back</a>
            <button type="submit" class="next-btn">Submit Booking</button>
        </div>
    </form>
</div>

<script>
let canvas = document.getElementById('signature-pad');
let ctx = canvas.getContext('2d');
let drawing = false;

canvas.width = canvas.offsetWidth;
canvas.height = canvas.offsetHeight;

canvas.addEventListener('mousedown', function(e) {
    drawing = true;
    ctx.beginPath();
    ctx.moveTo(e.offsetX, e.offsetY);
});
canvas.addEventListener('mousemove', function(e) {
    if (drawing) {
        ctx.lineTo(e.offsetX, e.offsetY);
        ctx.stroke();
    }
});
canvas.addEventListener('mouseup', function() { drawing = false; });
canvas.addEventListener('mouseleave', function() { drawing = false; });

function clearSignaturePad() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
}

function submitAgreementForm() {
    // Ensure signature is not empty
    let empty = isCanvasBlank(canvas);
    if (empty) {
        alert("Please provide your signature.");
        return false;
    }
    document.getElementById('signature_data').value = canvas.toDataURL();
    return true;
}
function isCanvasBlank(canvas) {
    const blank = document.createElement('canvas');
    blank.width = canvas.width;
    blank.height = canvas.height;
    return canvas.toDataURL() === blank.toDataURL();
}
</script>

<?php include '../includes/footer.php'; ?>