<?php

namespace App\Http\Controllers\Api;

use App\Contact;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    //
    public function addContact(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "name"      => "required",
            "type"      => "required",
            "address"   => "required",
            "phone"     => "required",
            "email"     => "required"
        ]);

        if ($validator->fails()) {
            $errorMsg = '';
            
            foreach ($validator->errors()->all() as $error)
            {
                $errorMsg .= $error . '<br>';
            }
            
            return response()->json([
                "status" => false,
                "message" => $errorMsg
            ], 400);
        }

        Contact::create([
            'name'          => $request->name,
            'type'          => $request->type,
            'address'       => $request->address,
            'phone'         => $request->phone,
            'email'         => $request->email,
            'behalf'        => $request->behalf ? $request->behalf : '',
            'created_by'    => auth()->user()->id,
            'updated_by'    => auth()->user()->id,
            'status'        => 1,
            'due_date'      => $request->due_date ?? 0
        ]);

        $description =  'New contact added' . chr(10) .
                        'Name : ' . $request->name . chr(10) .
                        'Type : ' . $request->type . chr(10) .
                        'Address :' . $request->address . chr(10) .
                        'Phone : ' . $request->phone  . chr(10) .
                        'Email : ' . $request->email . chr(10) .
                        'Behalf : ' . $request->behalf . chr(10) .
                        'Due Date : ' . $request->due_date . ' days';

        $this->history('contacts', 'add contact', $description);

        return response()->json([
            "status" => true,
            "message" => "Contact registered successfully"
        ]);
    }

    public function getContact(Request $request)
    {
        $sortBy = $request->input('sort_by', 'name');
        $sortOrder = $request->input('sort_order', 'desc');

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Contact::query();
        $contacts = $query->where('status', 1)->orderBy($sortBy, $sortOrder)->get();

        return response()->json([
            'status' => true,
            'data' => $contacts
        ]);
    }

    public function getContactById($id)
    {
        if (Contact::where('id', $id)->where('status', 1)->exists())
        {
            $contact = Contact::find($id);

            return response()->json([
                "status" => true,
                "data" => $contact
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Contact not found"
            ], 404);
        }

    }

    public function updateContact(Request $request, $id)
    {
        if (Contact::where('id', $id)->exists())
        {
            $contact = Contact::find($id);
            $contact->update([
                'name'          => $request->name ? $request->name : $contact->name,
                'type'          => $request->type ? $request->type : $contact->type,
                'address'       => $request->address ? $request->address : $contact->address,
                'phone'         => $request->phone ? $request->phone : $contact->phone,
                'email'         => $request->email ? $request->email : $contact->email,
                'behalf'        => $request->behalf ? $request->behalf : $contact->behalf,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id,
                'due_date'      => $request->due_date ? $request->due_date : $contact->due_date
            ]);

            return response()->json([
                "status" => true,
                "message" => "Contact updated successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Contact not found"
            ], 404);
        }
    }

    public function deleteContact($id)
    {
        if (Contact::where('id', $id)->exists())
        {
            $contact = Contact::find($id);
            $contact->update([
                'status'        => 0,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Contact deleted successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Contact not found"
            ], 404);
        }
    }

}
