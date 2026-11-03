export interface User {
    id: number;
    name: string;
    email: string;
    roles: string[];
    permissions: string[];
    property?: Property;
    avatar?: string;
}

export interface Property {
    id: number;
    name: string;
    code: string;
    city: string;
    country: string;
    currency: string;
    timezone: string;
    logo?: string;
    star_rating: number;
    network?: Network;
}

export interface Network {
    id: number;
    name: string;
    logo?: string;
    properties: Property[];
}

export interface Room {
    id: number;
    number: string;
    floor: number;
    type: RoomType;
    status: 'available' | 'occupied' | 'dirty' | 'maintenance' | 'reserved';
    is_clean: boolean;
    current_guest?: Guest;
    property_id: number;
}

export interface RoomType {
    id: number;
    name: string;
    code: string;
    max_occupancy: number;
    base_rate: number;
    amenities: string[];
    images: string[];
}

export interface Guest {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    phone: string;
    nationality: string;
    id_type: string;
    id_number: string;
    date_of_birth?: string;
    loyalty_member?: LoyaltyMember;
    avatar?: string;
}

export interface Reservation {
    id: number;
    confirmation_number: string;
    guest: Guest;
    room?: Room;
    room_type: RoomType;
    property: Property;
    check_in: string;
    check_out: string;
    nights: number;
    adults: number;
    children: number;
    status: 'pending' | 'confirmed' | 'checked_in' | 'checked_out' | 'cancelled' | 'no_show';
    source: string;
    rate_plan: string;
    total_amount: number;
    paid_amount: number;
    balance: number;
    special_requests?: string;
    created_at: string;
}

export interface LoyaltyMember {
    id: number;
    membership_number: string;
    tier: 'classic' | 'silver' | 'gold' | 'platinum' | 'diamond';
    points_balance: number;
    lifetime_points: number;
    lifetime_nights: number;
    joined_at: string;
    tier_expires_at?: string;
}

export interface PageProps {
    auth: {
        user: User;
    };
    flash?: {
        success?: string;
        error?: string;
    };
    property: Property;
    network: Network;
    [key: string]: unknown;
}
