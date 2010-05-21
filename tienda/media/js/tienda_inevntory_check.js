/**
 * This method will check the quantity of the selcted combination with required
 * quantity and the avilable quantity of the stock, and will make the Add to cart
 * button visible and invisible or Show out of stock message .
 * 
 */
function checkStock() {

	optionsArray = strignOfOptions.split('&&');

	var csv = null;
	var numberOfSelection = 0;
	var requiredQuantity = 0;
	for ($k = 0; $k < document.adminForm.elements.length; $k++) {
		if (document.adminForm.elements[$k].type == "select-one") {
			numberOfSelection++;
			if (numberOfSelection == 1) {
				csv = document.adminForm.elements[$k].value;
			} else {
				csv = csv + "," + document.adminForm.elements[$k].value;
			}
		}

		if (document.adminForm.elements[$k].type == "text") {
			requiredQuantity = parseInt(document.adminForm.elements[$k].value);
		}

	}

	for (k = 0; k < optionsArray.length - 1; k++) {
		var csvOfDataBase = (optionsArray[k].split('=>')[0]);
		var avialbleQuantity = parseInt((optionsArray[k].split('=>')[1]));
		
		if (csvOfDataBase == csv) {
			if (avialbleQuantity < requiredQuantity) {
				hide("add_to_cart");
				show("add_to_cart_deactive");
			} else {
				show("add_to_cart");
				hide("add_to_cart_deactive");
			}
		}
	}

}

var browserType;

if (document.layers) {
	browserType = "nn4"
}
if (document.all) {
	browserType = "ie"
}
if (window.navigator.userAgent.toLowerCase().match("gecko")) {
	browserType = "gecko"
}




/**
 * method to hide the div on the basis of Id
 * 
 * @param  string idByName (Id of the Div)
 */
function hide(idByName) {
	if (browserType == "gecko")
		document.poppedLayer = eval('document.getElementById(idByName)');
	else if (browserType == "ie")
		document.poppedLayer = eval('document.getElementById(idByName)');
	else
		document.poppedLayer = eval('document.layers["idByName"]');
	document.poppedLayer.style.display = "none";
}




/**
 * method to show the div on the basis of Id
 * 
 * @param  string idByName (Id of the Div)
 */
function show(idByName) {
	if (browserType == "gecko")
		document.poppedLayer = eval('document.getElementById(idByName)');
	else if (browserType == "ie")
		document.poppedLayer = eval('document.getElementById(idByName)');
	else
		document.poppedLayer = eval('document.layers[idByName]');
	document.poppedLayer.style.display = "block";;
}
