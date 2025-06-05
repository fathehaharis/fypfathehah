const steps = [
    'Vehicle',
    'Booking',
    'Driver Details',
    'Terms',
    'Payment',
];
let currentStep = 0;
let driverDetails = {}; // To pre-fill from DB

if (window.driverDetailsJson) {
    driverDetails = JSON.parse(window.driverDetailsJson);
}

function renderWizardBar() {
    const barContainer = document.getElementById('wizardBar');
    barContainer.innerHTML = '';
    const bar = document.createElement('div');
    bar.className = 'wizard-bar';
    steps.forEach((step, i) => {
        const div = document.createElement('div');
        div.className = 'wizard-step' + (i === 0 ? ' active' : '');
        div.id = 'stepLabel' + i;
        div.innerHTML = `
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 13l2.5 2.5 5-5" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            ${step}
        `;
        bar.appendChild(div);
    });
    barContainer.appendChild(bar);
}

function showStep(n) {
    renderWizardBar();
    document.querySelectorAll('.wizard-step').forEach((el,i)=>{
        el.classList.remove('active','completed');
        if(i<n) el.classList.add('completed');
        else if(i===n) el.classList.add('active');
    });
    renderStepContent(n);
    renderNav(n);
}

function nextStep() { if(validateStep(currentStep)) showStep(++currentStep);}
function prevStep() { if(currentStep>0) showStep(--currentStep);}

function renderStepContent(n) {
    const c = document.getElementById('wizardContent');
    c.innerHTML = '';
    if(n===0) {
        c.innerHTML = `
        <div class="wizard-content">
            <div class="car-card">
                <img class="car-img" src="get_car_image.php?car_image_id=${window.car.car_image_id}" alt="Car image"
                    onerror="this.src='/assets/images/viva_elite.png'">
                <div class="car-specs">
                    <div class="car-title">${window.car.car_brand} ${window.car.car_model}</div>
                    <div class="car-plate">Plate: ${window.car.plate_no}</div>
                    <div class="car-rate">RM ${parseFloat(window.car.daily_rate).toFixed(2)} / day</div>
                    <table class="spec-table">
                        <tr><td>Year&nbsp;</td><td>:</td><td>${window.car.year}</td></tr>
                        <tr><td>Color&nbsp;</td><td>:</td><td>${window.car.color}</td></tr>
                        <tr><td>Mileage&nbsp;</td><td>:</td><td>${window.car.mileage} km</td></tr>
                        <tr><td>Transmission&nbsp;</td><td>:</td><td>${window.car.transmission}</td></tr>
                        <tr><td>Seats&nbsp;</td><td>:</td><td>${window.car.seat_capacity}</td></tr>
                    </table>
                </div>
            </div>
        </div>`;
    }
    if(n===1) {
        c.innerHTML = `
        <div class="wizard-content">
            <div class="form-section">
                <label>Pickup Date & Time:
                    <div class="form-row">
                        <input type="date" name="pickup_date" id="pickup_date" min="${todayStr()}" required>
                        <input type="time" name="pickup_time" id="pickup_time" required>
                    </div>
                </label>
                <label>Return Date & Time:
                    <div class="form-row">
                        <input type="date" name="return_date" id="return_date" min="${futureStr(1)}" required>
                        <input type="time" name="return_time" id="return_time" required>
                    </div>
                </label>
                <label>Delivery Option:
                    <select name="delivery_type" id="delivery_type" required>
                        <option value="self_pickup">Pick up myself (FREE)</option>
                        <option value="delivery">Deliver car to me (+RM10)</option>
                        <option value="full_delivery">Deliver & return pickup (+RM30)</option>
                    </select>
                </label>
                <div id="delivery_location_wrapper" style="display:none; flex-direction:column; margin-top:10px;">
                    <label>Delivery Location:
                        <input type="text" name="delivery_location" id="delivery_location">
                    </label>
                </div>
            </div>
        </div>`;
        setTimeout(()=>{
            document.getElementById('delivery_type').addEventListener('change',toggleDeliveryLocation);
            toggleDeliveryLocation();
        },30);
    }
    if(n===2) {
        c.innerHTML = `
        <div class="wizard-content">
        <fieldset>
            <legend>Driver Details</legend>
            <div class="form-row">
                <label>Full Name: <input type="text" name="cust_name" required value="${driverDetails.full_name||''}"></label>
                <label>Phone No: <input type="text" name="cust_phone" required value="${driverDetails.phone_no||''}"></label>
            </div>
            <div class="form-row">
                <label>Email: <input type="email" name="cust_email" required value="${driverDetails.email||''}"></label>
                <label>Username: <input type="text" name="cust_username" required value="${driverDetails.username||''}"></label>
            </div>
            <div class="form-row">
                <label>License No: <input type="text" name="license_no" value="${driverDetails.license_no||''}"></label>
                <label>Passport No: <input type="text" name="passport_no" value="${driverDetails.passport_no||''}"></label>
            </div>
            <div class="form-row">
                <label>ID Number: <input type="text" name="id_no" value="${driverDetails.id_no||''}"></label>
                <label>Country: <input type="text" name="country" value="${driverDetails.country||''}"></label>
            </div>
            <div class="form-row">
                <label>Address: <input type="text" name="address" value="${driverDetails.address||''}"></label>
                <label>Age: <input type="number" name="age" min="18" value="${driverDetails.age||''}"></label>
            </div>
            <div class="form-row">
                <label>ID Front Image: <input type="file" name="id_front_image" accept="image/*"></label>
                <label>ID Back Image: <input type="file" name="id_back_image" accept="image/*"></label>
            </div>
        </fieldset>
        <fieldset>
            <legend>Guarantor Details</legend>
            <div class="form-row">
                <label>Full Name: <input type="text" name="guarantor_name" required></label>
                <label>Phone No: <input type="text" name="guarantor_phone" required></label>
            </div>
            <div class="form-row">
                <label>ID Number: <input type="text" name="guarantor_id_no"></label>
                <label>Relationship: <input type="text" name="guarantor_relationship" required></label>
            </div>
            <div class="form-row">
                <label>ID Front Image: <input type="file" name="guarantor_id_front_image" accept="image/*"></label>
                <label>ID Back Image: <input type="file" name="guarantor_id_back_image" accept="image/*"></label>
            </div>
        </fieldset>
        </div>`;
    }
    if(n===3) {
        c.innerHTML = `
        <div class="wizard-content">
            <h3>Terms and Conditions</h3>
            <ol style="font-size:.98em;color:#333;">
                <li>Car must be returned in the same condition as rented.</li>
                <li>Any damage or late return may incur additional charges.</li>
                <li>No smoking or pets allowed in the vehicle.</li>
                <li>Driver is responsible for all traffic offenses during rental period.</li>
                <li>See full terms on our website or request a printed copy.</li>
            </ol>
            <label>Signature:</label><br>
            <canvas id="signaturePad" width="380" height="120" class="signature-pad"></canvas>
            <input type="hidden" name="cust_signature" id="cust_signature">
            <button type="button" style="margin-left:18px;padding:4px 14px;font-size:.96em;" onclick="clearSignature()">Clear</button>
        </div>`;
        setTimeout(initSignaturePad, 30);
    }
    if(n===4) {
        c.innerHTML = `
        <div class="wizard-content">
            <h3 style="color:#2f377d;">Payment</h3>
            <p>Total Price: <span id="totalPriceDisplay">RM 0.00</span></p>
            <p>-- Payment integration form here (FPX, credit card, etc) --</p>
            <button type="submit" class="book-btn">Pay & Book Now</button>
        </div>`;
        setTimeout(updateTotal,30);
    }
}
function renderNav(n) {
    const nav = document.getElementById('wizardNav');
    nav.innerHTML = '';
    nav.style.display = "flex";
    nav.style.justifyContent = "center";
    nav.style.gap = "16px";
    if(n>0) {
        const back = document.createElement('button');
        back.type = 'button';
        back.className = 'prev-btn';
        back.innerText = 'Back';
        back.onclick = prevStep;
        nav.appendChild(back);
    }
    if(n<steps.length-1) {
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'next-btn';
        next.innerText = 'Next';
        next.onclick = n===3 ? captureSignatureAndNext : nextStep;
        nav.appendChild(next);
    }
}
function validateStep(n) {
    // Add custom validation per step if needed
    return true;
}
function toggleDeliveryLocation() {
    var deliveryType = document.getElementById('delivery_type').value;
    document.getElementById('delivery_location_wrapper').style.display =
        (deliveryType === 'delivery' || deliveryType === 'full_delivery') ? 'flex' : 'none';
}
function todayStr() {
    const d = new Date();
    return d.toISOString().split('T')[0];
}
function futureStr(n) {
    const d = new Date();
    d.setDate(d.getDate()+n);
    return d.toISOString().split('T')[0];
}
function initSignaturePad() {
    let canvas = document.getElementById('signaturePad');
    let ctx = canvas.getContext('2d');
    let drawing = false, lastX=0, lastY=0;
    function startDraw(e){drawing=true;[lastX,lastY]=[e.offsetX,e.offsetY];}
    function draw(e){
        if(!drawing) return;
        ctx.lineWidth=2;ctx.lineCap='round';ctx.strokeStyle='#1976d2';
        ctx.beginPath();ctx.moveTo(lastX,lastY);ctx.lineTo(e.offsetX,e.offsetY);ctx.stroke();
        [lastX,lastY]=[e.offsetX,e.offsetY];
    }
    function stopDraw(){drawing=false;}
    canvas.onmousedown = startDraw;
    canvas.onmousemove = draw;
    canvas.onmouseup = stopDraw;
    canvas.onmouseout = stopDraw;
    window.clearSignature = function(){ ctx.clearRect(0,0,canvas.width,canvas.height);}
}
function captureSignatureAndNext(){
    let canvas = document.getElementById('signaturePad');
    document.getElementById('cust_signature').value = canvas.toDataURL();
    nextStep();
}
function updateTotal() {
    let start = document.getElementById('pickup_date')?.value;
    let end = document.getElementById('return_date')?.value;
    let rate = parseFloat(window.car.daily_rate||0);
    let delivery = document.getElementById('delivery_type')?.value;
    let fee = 0;
    if(delivery==='delivery') fee=10;
    else if(delivery==='full_delivery') fee=30;
    let days = 1;
    if(start && end) {
        let s = new Date(start), e = new Date(end);
        let diff = Math.round((e-s)/(1000*60*60*24));
        days = diff>0?diff:1;
    }
    let total = (days*rate) + fee;
    document.getElementById('totalPriceDisplay').innerText = 'RM ' + total.toFixed(2);
}
document.addEventListener('DOMContentLoaded',function(){
    renderWizardBar();
    showStep(0);
});